<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Cache\Cache;
use LanguageServer\FilesFinder\FilesFinder;
use LanguageServer\Index\{DependenciesIndex, Index};
use LanguageServer\Protocol\Message;
use LanguageServer\Protocol\Diagnostic;
use LanguageServer\Protocol\MessageType;
use LanguageServer\Protocol\Range;
use LanguageServer\Protocol\Position;
use LanguageServer\ContentRetriever\ContentRetriever;
use Webmozart\PathUtil\Path;
use Composer\Semver\VersionParser;
use Sabre\Event\Promise;
use function Sabre\Event\coroutine;

require_once dirname(__FILE__).'/../sertest.php';

class Indexer
{
    /**
     * @var int The prefix for every cache item
     */
    const CACHE_VERSION = 2;

    /**
     * @var FilesFinder
     */
    private $filesFinder;

    /**
     * @var string
     */
    private $rootPath;

    /**
     * @var LanguageClient
     */
    private $client;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var DependenciesIndex
     */
    private $dependenciesIndex;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @var PhpDocumentLoader
     */
    private $documentLoader;

    /**
     * @var \stdClasss
     */
    private $composerLock;

    /**
     * @var \stdClasss
     */
    private $composerJson;

    /**
     * @param FilesFinder       $filesFinder
     * @param string            $rootPath
     * @param LanguageClient    $client
     * @param Cache             $cache
     * @param DependenciesIndex $dependenciesIndex
     * @param Index             $sourceIndex
     * @param PhpDocumentLoader $documentLoader
     * @param \stdClass|null    $composerLock
     */
    public function __construct(
        FilesFinder $filesFinder,
        string $rootPath,
        LanguageClient $client,
        ContentRetriever $documentLoader,
        $db
    ) {
        $this->filesFinder = $filesFinder;
        $this->rootPath = $rootPath;
        $this->client = $client;
        $this->documentLoader = $documentLoader;
        $this->db = $db;
    }

    /**
     * Will read and parse the passed source files in the project and add them to the appropiate indexes
     *
     * @return Promise <void>
     */
    public function index(): Promise
    {
        return coroutine(function () {

            $pattern = Path::makeAbsolute('**/*.php', __DIR__ . '/../vendor/jetbrains/phpstorm-stubs');
            $stubs = yield $this->filesFinder->find($pattern);

            $pattern = Path::makeAbsolute('**/*.php', $this->rootPath);
            $uris = yield $this->filesFinder->find($pattern);
            $uris = array_merge($stubs, $uris);

            $count = count($uris);
            $startTime = microtime(true);
            $this->client->window->logMessage(MessageType::INFO, "$count files total");

            /** @var string[] */
            $source = [];
            /** @var string[][] */
            $deps = [];

            foreach ($uris as $uri) {
                // Source file
                $source[] = $uri;
            }

            // Index source
            // Definitions and static references
            $this->client->window->logMessage(MessageType::INFO, 'Indexing project for definitions and static references');
            yield $this->indexFiles($source);

            $duration = (int)(microtime(true) - $startTime);
            $mem = (int)(memory_get_usage(true) / (1024 * 1024));
            $this->client->window->logMessage(
                MessageType::INFO,
                "All $count PHP files parsed in $duration seconds. $mem MiB allocated."
            );
        });
    }

    /**
     * @param array $files
     * @return Promise
     */
    private function indexFiles(array $files): Promise
    {
        return coroutine(function () use ($files) {
            $cache = $this->rootPath.'/phpls.cache';
            if (file_exists($cache) && is_readable($cache)) {
                try {
                    $this->client->window->logMessage(MessageType::LOG, "Loading symbol cache");
                    yield timeout();
                    $db = \unserialize(file_get_contents($cache));
                    \gc_collect_cycles();
                    \gc_mem_caches();
                    if ($db) {
                        $this->db->from($db);
                    }
                }
                catch (\Exception $e) {
                    $this->client->window->logMessage(MessageType::LOG, "Error loading cache: {$e->getMessage()}");
                }
            }

            foreach ($files as $i => $uri) {
                // Give LS to the chance to handle requests while indexing
                yield timeout();
                $contents = yield $this->documentLoader->retrieve($uri);

                if (!isset($this->db->files[$uri]) || \hash('sha256', $contents) !== $this->db->files[$uri]->hash()) {
                    $this->client->window->logMessage(MessageType::LOG, "Parsing $uri");
                    $parser = new \Microsoft\PhpParser\Parser();
                    $ast = $parser->parseSourceFile($contents, $uri);
                    $collector = new \LanguageServer\CodeDB\Collector($this->db, $uri, $ast);
                    $collector->iterate($ast);
                }
            }

            $this->client->window->logMessage(MessageType::LOG, "Resolving references");
            $start = microtime(true);
            $this->db->resolveReferences();
            $unresolved = $this->db->getUnresolvedReferenceCount();
            $duration = (int)(microtime(true) - $start);
            $this->client->window->logMessage(MessageType::LOG, "Resolved references in {$duration} seconds.");
            $this->client->window->logMessage(MessageType::LOG, "{$unresolved} unresolved references remaining.");
            $this->client->window->logMessage(MessageType::LOG, "----------------------------------------------");
            $numsyms = 0;
            $numrefs = 0;
            foreach($this->db->files as $file) {
                $numsyms++;
                $numrefs += count($file->references ?? []);
                foreach($file->children ?? [] as $namespace) {
                    $numsyms++;
                    foreach($namespace->children ?? [] as $sym1) {
                        $numsyms++;
                        foreach($sym1->children ?? [] as $sym2) {
                            $numsyms++;
                            foreach($sym2->children ?? [] as $sym3) {
                                $numsyms++;
                            }
                        }
                    }
                }
            }
            $this->client->window->logMessage(MessageType::LOG, "{$numsyms} Symbols");
            $this->client->window->logMessage(MessageType::LOG, "{$numrefs} References");

            $diags = [];
            foreach ($files as $i => $uri) {
                $file = $this->db->files[$uri];
                if (is_array($file->diagnostics)) {
                    foreach($file->diagnostics as $diag) {
                        $diags[$uri][] = new Diagnostic(
                            $diag->message,
                            $diag->getRange($file),
                            0,
                            0,
                            null
                        );
                    }
                }
            }
            foreach($this->db->references as $refs) {
                foreach($refs as $ref) {
                    $diags[$ref->file->name][] = new Diagnostic(
                        "Unresolved reference \"{$ref->target}\"",
                        $ref->file->getRange($ref->getStart(), $ref->getLength()),
                        0,
                        0,
                        null
                    );
                }
            }
            foreach($diags as $uri => $d) {
                if ($d) {
                    $this->client->textDocument->publishDiagnostics($uri, $d);
                }
            }

            $cache = $this->rootPath.'/phpls.cache';
            file_put_contents($cache, \serialize($this->db));
            \gc_collect_cycles();
            \gc_mem_caches();
        });
    }
}
