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

    private $db;

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

            $this->db->startIndex();
            foreach ($files as $i => $uri) {
                // Give LS to the chance to handle requests while indexing
                yield timeout();
                $contents = yield $this->documentLoader->retrieve($uri);

                if (!$this->db->hasFileWithHash($uri, \hash('sha256', $contents))) {
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
            $duration = (int)(microtime(true) - $start);
            $this->client->window->logMessage(MessageType::LOG, "Resolved references in {$duration} seconds.");
            $this->client->window->logMessage(MessageType::LOG, "----------------------------------------------");
            $numsyms = 0;
            $numrefs = 0;
            // TODO numsyms
            $this->client->window->logMessage(MessageType::LOG, "{$numsyms} Symbols");
            $this->client->window->logMessage(MessageType::LOG, "{$numrefs} References");

            $diags = [];
            // TODO diags from files
            // TODO diags for unresolved refs
            foreach($diags as $uri => $d) {
                if ($d) {
                    $this->client->textDocument->publishDiagnostics($uri, $d);
                }
            }

            $this->db->finishIndex();
        });
    }
}
