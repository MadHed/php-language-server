<?php
declare(strict_types = 1);

namespace LanguageServer\Server;

use LanguageServer\{LanguageClient, Project, PhpDocumentLoader};
use LanguageServer\Index\{ProjectIndex, DependenciesIndex, Index};
use LanguageServer\Protocol\{
    FileChangeType,
    FileEvent,
    SymbolInformation,
    SymbolDescriptor,
    PackageDescriptor,
    ReferenceInformation,
    DependencyReference,
    Location,
    MessageType,
    SymbolKind,
    Range,
    Position
};
use Sabre\Event\Promise;
use function Sabre\Event\coroutine;
use function LanguageServer\waitForEvent;
use LanguageServer\CodeDB\Symbol;

/**
 * Provides method handlers for all workspace/* methods
 */
class Workspace
{
    /**
     * @var LanguageClient
     */
    public $client;

    private $db;

    /**
     * @param LanguageClient    $client            LanguageClient instance used to signal updated results
     * @param ProjectIndex      $projectIndex      Index that is used to wait for full index completeness
     * @param DependenciesIndex $dependenciesIndex Index that is used on a workspace/xreferences request
     * @param DependenciesIndex $sourceIndex       Index that is used on a workspace/xreferences request
     * @param \stdClass         $composerLock      The parsed composer.lock of the project, if any
     * @param PhpDocumentLoader $documentLoader    PhpDocumentLoader instance to load documents
     */
    public function __construct(
        LanguageClient $client,
        \LanguageServer\CodeDB\Repository $db
    ) {
        $this->client = $client;
        $this->db = $db;
    }

    /**
     * The workspace symbol request is sent from the client to the server to list project-wide symbols matching the query string.
     *
     * @param string $query
     * @return Promise <SymbolInformation[]>
     */
    public function symbol(string $query): Promise
    {
        return coroutine(function () use ($query) {
            $symbols = $this->db->getSymbolsByName($query);

            yield;
            $results = [];
            foreach($symbols as $symbol) {
                $kind = SymbolKind::CONSTANT;
                if ($symbol->type == Symbol::_CLASS) {
                    $kind = SymbolKind::CLASS_;
                }
                else if ($symbol->type == Symbol::_INTERFACE) {
                    $kind = SymbolKind::INTERFACE;
                }
                else if ($symbol->type == Symbol::_FUNCTION) {
                    $kind = SymbolKind::FUNCTION;
                }
                else if ($symbol->type == Symbol::_VARIABLE) {
                    $kind = SymbolKind::VARIABLE;
                }
                else if ($symbol->type == Symbol::_NAMESPACE) {
                    $kind = SymbolKind::NAMESPACE;
                }

                $results[] = new \LanguageServer\Protocol\SymbolInformation(
                    $symbol->name,
                    $kind,
                    new \LanguageServer\Protocol\Location(
                        $symbol->uri,
                        new Range(new Position((int)$symbol->range_start_line, (int)$symbol->range_start_character), new Position((int)$symbol->range_end_line, (int)$symbol->range_end_character))
                    ),
                    $symbol->parent_fqn
                );
            }
            return $results;
        });
        /*return coroutine(function () use ($query) {
            // Wait until indexing for definitions finished
            if (!$this->sourceIndex->isStaticComplete()) {
                yield waitForEvent($this->sourceIndex, 'static-complete');
            }
            $symbols = [];
            foreach ($this->sourceIndex->getDefinitions() as $fqn => $definition) {
                if ($query === '' || stripos($fqn, $query) !== false) {
                    $symbols[] = $definition->symbolInformation;
                }
            }
            return $symbols;
        });*/
    }

    /**
     * The watched files notification is sent from the client to the server when the client detects changes to files watched by the language client.
     *
     * @param FileEvent[] $changes
     * @return void
     */
    public function didChangeWatchedFiles(array $changes)
    {
        foreach ($changes as $change) {
            if ($change->type === FileChangeType::DELETED) {
                $this->client->textDocument->publishDiagnostics($change->uri, []);
            }
        }
    }

    /**
     * The workspace references request is sent from the client to the server to locate project-wide references to a symbol given its description / metadata.
     *
     * @param SymbolDescriptor $query Partial metadata about the symbol that is being searched for.
     * @param string[]         $files An optional list of files to restrict the search to.
     * @return ReferenceInformation[]
     */
    public function xreferences($query, array $files = null): Promise
    {
        // TODO: $files is unused in the coroutine
        return coroutine(function () use ($query, $files) {
            if ($this->composerLock === null) {
                return [];
            }
            // Wait until indexing finished
            if (!$this->projectIndex->isComplete()) {
                yield waitForEvent($this->projectIndex, 'complete');
            }
            /** Map from URI to array of referenced FQNs in dependencies */
            $refs = [];
            // Get all references TO dependencies
            $fqns = isset($query->fqsen) ? [$query->fqsen] : array_values($this->dependenciesIndex->getDefinitions());
            foreach ($fqns as $fqn) {
                foreach ($this->sourceIndex->getReferenceUris($fqn) as $uri) {
                    if (!isset($refs[$uri])) {
                        $refs[$uri] = [];
                    }
                    if (array_search($uri, $refs[$uri]) === false) {
                        $refs[$uri][] = $fqn;
                    }
                }
            }
            $refInfos = [];
            foreach ($refs as $uri => $fqns) {
                foreach ($fqns as $fqn) {
                    $doc = yield $this->documentLoader->getOrLoad($uri);
                    foreach ($doc->getReferenceNodesByFqn($fqn) as $node) {
                        $refInfo = new ReferenceInformation;
                        $refInfo->reference = Location::fromNode($node);
                        $refInfo->symbol = $query;
                        $refInfos[] = $refInfo;
                    }
                }
            }
            return $refInfos;
        });
    }

    /**
     * @return DependencyReference[]
     */
    public function xdependencies(): array
    {
        if ($this->composerLock === null) {
            return [];
        }
        $dependencyReferences = [];
        foreach (array_merge($this->composerLock->packages, $this->composerLock->{'packages-dev'}) as $package) {
            $dependencyReferences[] = new DependencyReference($package);
        }
        return $dependencyReferences;
    }
}
