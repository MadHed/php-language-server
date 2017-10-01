<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Cache\Cache;
use LanguageServer\FilesFinder\FilesFinder;
use LanguageServer\Index\{DependenciesIndex, Index};
use LanguageServer\Protocol\Message;
use LanguageServer\Protocol\MessageType;
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

            $pattern = Path::makeAbsolute('**/*.php', $this->rootPath);
            $uris = yield $this->filesFinder->find($pattern);

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
                if (isset($this->db->files[$uri]) && \hash('sha256', $contents) === $this->db->files[$uri]->hash()) {
                    $this->client->window->logMessage(MessageType::LOG, "$uri not changed");
                    continue;
                }
                $this->client->window->logMessage(MessageType::LOG, "Parsing $uri");
                $parser = new \Microsoft\PhpParser\Parser();
                $ast = $parser->parseSourceFile($contents, $uri);
                $collector = new \LanguageServer\CodeDB\Collector($this->db, $uri, $ast);
                $collector->iterate($ast);
            }

            $this->client->window->logMessage(MessageType::LOG, "Resolving references");
            $this->db->resolveReferences();
            $this->client->window->logMessage(MessageType::LOG, "Resolved references.");

            $cache = $this->rootPath.'/phpls.cache';
            file_put_contents($cache, \serialize($this->db));
            \gc_collect_cycles();
        });
    }
}
