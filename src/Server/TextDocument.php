<?php
declare(strict_types = 1);

namespace LanguageServer\Server;

use LanguageServer\{
    CompletionProvider, LanguageClient, PhpDocument, PhpDocumentLoader, DefinitionResolver
};
use LanguageServer\Index\ReadableIndex;
use LanguageServer\Protocol\{
    FormattingOptions,
    Hover,
    Location,
    MarkedString,
    Position,
    Range,
    ReferenceContext,
    SymbolDescriptor,
    PackageDescriptor,
    SymbolLocationInformation,
    TextDocumentIdentifier,
    TextDocumentItem,
    VersionedTextDocumentIdentifier,
    CompletionContext,
    SymbolKind,
    CodeLens,
    Command,
    Diagnostic
};
use Microsoft\PhpParser;
use Microsoft\PhpParser\Node;
use Sabre\Event\Promise;
use Sabre\Uri;
use function LanguageServer\{
    isVendored, waitForEvent, getPackageName, timeout
};
use function Sabre\Event\coroutine;

/**
 * Provides method handlers for all textDocument/* methods
 */
class TextDocument
{
    /**
     * The lanugage client object to call methods on the client
     *
     * @var \LanguageServer\LanguageClient
     */
    protected $client;

    private $db;

    /**
     * @param PhpDocumentLoader $documentLoader
     * @param DefinitionResolver $definitionResolver
     * @param LanguageClient $client
     * @param ReadableIndex $index
     * @param \stdClass $composerJson
     * @param \stdClass $composerLock
     */
    public function __construct(
        LanguageClient $client,
        \LanguageServer\CodeDB\Repository $db
    ) {
        $this->client = $client;
        $this->db = $db;
    }

    public function codeLens(TextDocumentIdentifier $textDocument): Promise
    {
        return coroutine(function () use ($textDocument) {
            $codeLens = [];
            $file = $this->db->files[$textDocument->uri] ?? null;
            if ($file === null) {
                return $codeLens;
            }

            $symbols = [];
            foreach($file->children ?? [] as $ns) {
                yield null;
                $symbols[] = $ns;
                foreach($ns->children ?? [] as $sym) {
                    $symbols[] = $sym;
                    foreach($sym->children ?? [] as $syms) {
                        $symbols[] = $syms;
                    }
                }
            }

            foreach($symbols as $sym) {
                $cl = new CodeLens;
                $cl->range = $file->getRange($sym->start, $sym->length);
                $cmd = new Command;
                $cmd->title = count($sym->backRefs ?? []).' references';
                $cmd->command = '';
                $cl->command = $cmd;
                $codeLens[] = $cl;
            }

            return $codeLens;
        });
    }

    /**
     * The document symbol request is sent from the client to the server to list all symbols found in a given text
     * document.
     *
     * @param \LanguageServer\Protocol\TextDocumentIdentifier $textDocument
     * @return Promise <SymbolInformation[]>
     */
    public function documentSymbol(TextDocumentIdentifier $textDocument): Promise
    {
        return coroutine(function () use ($textDocument) {
            $file = $this->db->files[$textDocument->uri];
            $symbols = (
                new \LanguageServer\CodeDB\MultiIterator(
                    $this->db->files()->filter(\LanguageServer\CodeDB\nameEquals($textDocument->uri))->namespaces(),
                    $this->db->files()->filter(\LanguageServer\CodeDB\nameEquals($textDocument->uri))->namespaces()->classes(),
                    $this->db->files()->filter(\LanguageServer\CodeDB\nameEquals($textDocument->uri))->namespaces()->classes()->symbols()
                ));

            yield null;
            $results = [];
            foreach($symbols as $symbol) {
                $kind = SymbolKind::CONSTANT;
                if ($symbol instanceof \LanguageServer\CodeDB\Class_) {
                    $kind = SymbolKind::CLASS_;
                }
                else if ($symbol instanceof \LanguageServer\CodeDB\Interface_) {
                    $kind = SymbolKind::INTERFACE;
                }
                else if ($symbol instanceof \LanguageServer\CodeDB\Function_) {
                    $kind = SymbolKind::FUNCTION;
                }
                else if ($symbol instanceof \LanguageServer\CodeDB\Variable) {
                    $kind = SymbolKind::VARIABLE;
                }
                else if ($symbol instanceof \LanguageServer\CodeDB\File) {
                    $kind = SymbolKind::FILE;
                }
                else if ($symbol instanceof \LanguageServer\CodeDB\Namespace_) {
                    $kind = SymbolKind::NAMESPACE;
                }

                $results[] = new \LanguageServer\Protocol\SymbolInformation(
                    $symbol->name,
                    $kind,
                    new \LanguageServer\Protocol\Location(
                        $textDocument->uri,
                        $file->getRange($symbol->start, $symbol->length)
                    ),
                    $symbol->parent ? $symbol->parent->fqn() : ''
                );
            }
            return $results;
        });
    }

    /**
     * The document open notification is sent from the client to the server to signal newly opened text documents. The
     * document's truth is now managed by the client and the server must not try to read the document's truth using the
     * document's uri.
     *
     * @param \LanguageServer\Protocol\TextDocumentItem $textDocument The document that was opened.
     * @return void
     */
    public function didOpen(TextDocumentItem $textDocument)
    {
        return coroutine(function () use($textDocument) {
            yield null;
        });
        /* $document = $this->documentLoader->open($textDocument->uri, $textDocument->text);
        if (!isVendored($document, $this->composerJson)) {
            $this->client->textDocument->publishDiagnostics($textDocument->uri, $document->getDiagnostics());
        } */
    }

    /**
     * The document change notification is sent from the client to the server to signal changes to a text document.
     *
     * @param \LanguageServer\Protocol\VersionedTextDocumentIdentifier $textDocument
     * @param \LanguageServer\Protocol\TextDocumentContentChangeEvent[] $contentChanges
     * @return void
     */
    public function didChange(VersionedTextDocumentIdentifier $textDocument, array $contentChanges)
    {
        return coroutine(function () use($textDocument, $contentChanges) {
            yield null;
            $this->db->removeFile($textDocument->uri);
            $parser = new \Microsoft\PhpParser\Parser();
            $ast = $parser->parseSourceFile($contentChanges[0]->text, $textDocument->uri);
            $collector = new \LanguageServer\CodeDB\Collector($this->db, $textDocument->uri, $ast);
            $collector->iterate($ast);
            $this->db->resolveReferences();
            $file = $collector->file;
            $diags = [];
            if (is_array($file->diagnostics)) {
                foreach($file->diagnostics as $diag) {
                    $diags[] = new Diagnostic(
                        $diag->message,
                        $diag->getRange($file),
                        0,
                        0,
                        null
                    );
                }
            }
            foreach($this->db->references as $ref) {
                if (is_string($ref->target) && $ref->file === $file) {
                    $diags[] = new Diagnostic(
                        "Unresolved reference \"{$ref->target}\"",
                        $$file->getRange($ref->start, $ref->length),
                        0,
                        0,
                        null
                    );
                }
            }
            $this->client->textDocument->publishDiagnostics($textDocument->uri, $diags);
        });
        /* $document = $this->documentLoader->get($textDocument->uri);
        $document->updateContent($contentChanges[0]->text);
        $this->client->textDocument->publishDiagnostics($textDocument->uri, $document->getDiagnostics()); */
    }

    /**
     * The document close notification is sent from the client to the server when the document got closed in the client.
     * The document's truth now exists where the document's uri points to (e.g. if the document's uri is a file uri the
     * truth now exists on disk).
     *
     * @param \LanguageServer\Protocol\TextDocumentIdentifier $textDocument The document that was closed
     * @return void
     */
    public function didClose(TextDocumentIdentifier $textDocument)
    {
        return coroutine(function () {yield null;});
        /* $this->documentLoader->close($textDocument->uri); */
    }

    /**
     * The document formatting request is sent from the server to the client to format a whole document.
     *
     * @param TextDocumentIdentifier $textDocument The document to format
     * @param FormattingOptions $options The format options
     * @return Promise <TextEdit[]>
     */
    public function formatting(TextDocumentIdentifier $textDocument, FormattingOptions $options)
    {
        return coroutine(function () {yield null;});
        /* return $this->documentLoader->getOrLoad($textDocument->uri)->then(function (PhpDocument $document) {
            return $document->getFormattedText();
        }); */
    }

    /**
     * The references request is sent from the client to the server to resolve project-wide references for the symbol
     * denoted by the given text document position.
     *
     * @param ReferenceContext $context
     * @return Promise <Location[]>
     */
    public function references(
        ReferenceContext $context,
        TextDocumentIdentifier $textDocument,
        Position $position
    ): Promise {
        return coroutine(function () use($textDocument, $position) {
            yield null;
            if (!isset($this->db->files[$textDocument->uri])) return [];
            $file = $this->db->files[$textDocument->uri];
            $sym = $file->getSymbolAtPosition($position->line, $position->character);
            if (!$sym) {
                $ref = $this->db->getReferenceAtPosition($file, $position->line, $position->character);
                if (!$ref || !$ref->target instanceof \LanguageServer\CodeDB\Symbol) return [];
                $sym = $ref->target;
            }
            if (!$sym) return [];
            $backRefs = $sym->backRefs ?? [];
            $locations = [];
            foreach($backRefs as $ref) {
                $locations[] = new Location(
                    $ref->file->name,
                    $ref->file->getRange($ref->start, $ref->length)
                );
            }
            return $locations;
        });
        /* return coroutine(function () use ($textDocument, $position) {
            $document = yield $this->documentLoader->getOrLoad($textDocument->uri);
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return [];
            }
            $locations = [];
            // Variables always stay in the boundary of the file and need to be searched inside their function scope
            // by traversing the AST
            if (

            ($node instanceof Node\Expression\Variable && !($node->getParent()->getParent() instanceof Node\PropertyDeclaration))
                || $node instanceof Node\Parameter
                || $node instanceof Node\UseVariableName
            ) {
                if (isset($node->name) && $node->name instanceof Node\Expression) {
                    return null;
                }
                // Find function/method/closure scope
                $n = $node;

                $n = $n->getFirstAncestor(Node\Statement\FunctionDeclaration::class, Node\MethodDeclaration::class, Node\Expression\AnonymousFunctionCreationExpression::class, Node\SourceFileNode::class);

                if ($n === null) {
                    $n = $node->getFirstAncestor(Node\Statement\ExpressionStatement::class)->getParent();
                }

                foreach ($n->getDescendantNodes() as $descendantNode) {
                    if ($descendantNode instanceof Node\Expression\Variable &&
                        $descendantNode->getName() === $node->getName()
                    ) {
                        $locations[] = Location::fromNode($descendantNode);
                    }
                }
            } else {
                // Definition with a global FQN
                $fqn = DefinitionResolver::getDefinedFqn($node);

                // Wait until indexing finished
                if (!$this->index->isComplete()) {
                    yield waitForEvent($this->index, 'complete');
                }
                if ($fqn === null) {
                    $fqn = $this->definitionResolver->resolveReferenceNodeToFqn($node);
                    if ($fqn === null) {
                        return [];
                    }
                }
                $refDocuments = yield Promise\all(array_map(
                    [$this->documentLoader, 'getOrLoad'],
                    $this->index->getReferenceUris($fqn)
                ));
                foreach ($refDocuments as $document) {
                    $refs = $document->getReferenceNodesByFqn($fqn);
                    if ($refs !== null) {
                        foreach ($refs as $ref) {
                            $locations[] = Location::fromNode($ref);
                        }
                    }
                }
            }
            return $locations;
        }); */
    }

    /**
     * The goto definition request is sent from the client to the server to resolve the definition location of a symbol
     * at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Location|Location[]>
     */
    public function definition(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(function () use($textDocument, $position) {
            yield null;
            if (!isset($this->db->files[$textDocument->uri])) return [];
            $file = $this->db->files[$textDocument->uri];
            $ref = $this->db->getReferenceAtPosition($file, $position->line, $position->character);
            if (!$ref) return [];
            if (!$ref->target instanceof \LanguageServer\CodeDB\Symbol) return [];
            return new Location(
                $ref->target->getFile()->name,
                $ref->file->getRange($ref->target->start, $ref->target->length)
            );
        });
        /* return coroutine(function () use ($textDocument, $position) {
            $document = yield $this->documentLoader->getOrLoad($textDocument->uri);
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return [];
            }
            // Handle definition nodes
            $fqn = DefinitionResolver::getDefinedFqn($node);
            while (true) {
                if ($fqn) {
                    $def = $this->index->getDefinition($fqn);
                } else {
                    // Handle reference nodes
                    $def = $this->definitionResolver->resolveReferenceNodeToDefinition($node);
                }
                // If no result was found and we are still indexing, try again after the index was updated
                if ($def !== null || $this->index->isComplete()) {
                    break;
                }
                yield waitForEvent($this->index, 'definition-added');
            }
            if (
                $def === null
                || $def->symbolInformation === null
                || Uri\parse($def->symbolInformation->location->uri)['scheme'] === 'phpstubs'
            ) {
                return [];
            }
            return $def->symbolInformation->location;
        }); */
    }

    /**
     * The hover request is sent from the client to the server to request hover information at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Hover>
     */
    public function hover(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(function () use($textDocument, $position) {
            yield null;
            if (!isset($this->db->files[$textDocument->uri])) return null;
            $file = $this->db->files[$textDocument->uri];
            $ref = $this->db->getReferenceAtPosition($file, $position->line, $position->character);
            if (!$ref) return null;
            if (!$ref->target instanceof \LanguageServer\CodeDB\Symbol) return null;

            return new Hover(
                $ref->getDescription(),
                $ref->file->getRange($ref->start, $ref->length)
            );
        });
    }

    /**
     * The Completion request is sent from the client to the server to compute completion items at a given cursor
     * position. Completion items are presented in the IntelliSense user interface. If computing full completion items
     * is expensive, servers can additionally provide a handler for the completion item resolve request
     * ('completionItem/resolve'). This request is sent when a completion item is selected in the user interface. A
     * typically use case is for example: the 'textDocument/completion' request doesn't fill in the documentation
     * property for returned completion items since it is expensive to compute. When the item is selected in the user
     * interface then a 'completionItem/resolve' request is sent with the selected completion item as a param. The
     * returned completion item should have the documentation property filled in.
     *
     * @param TextDocumentIdentifier The text document
     * @param Position $position The position
     * @param CompletionContext|null $context The completion context
     * @return Promise <CompletionItem[]|CompletionList>
     */
    public function completion(TextDocumentIdentifier $textDocument, Position $position, CompletionContext $context = null): Promise
    {
        return coroutine(function () {yield null;});
        /* return coroutine(function () use ($textDocument, $position) {
            $document = yield $this->documentLoader->getOrLoad($textDocument->uri);
            return $this->completionProvider->provideCompletion($document, $position);
        }); */
    }

    /**
     * This method is the same as textDocument/definition, except that
     *
     * The method returns metadata about the definition (the same metadata that workspace/xreferences searches for).
     * The concrete location to the definition (location field) is optional. This is useful because the language server
     * might not be able to resolve a goto definition request to a concrete location (e.g. due to lack of dependencies)
     * but still may know some information about it.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position               $position     The position inside the text document
     * @return Promise <SymbolLocationInformation[]>
     */
    public function xdefinition(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(function () {yield null;});
        /* return coroutine(function () use ($textDocument, $position) {
            $document = yield $this->documentLoader->getOrLoad($textDocument->uri);
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return [];
            }
            // Handle definition nodes
            $fqn = DefinitionResolver::getDefinedFqn($node);
            while (true) {
                if ($fqn) {
                    $def = $this->index->getDefinition($fqn);
                } else {
                    // Handle reference nodes
                    $def = $this->definitionResolver->resolveReferenceNodeToDefinition($node);
                }
                // If no result was found and we are still indexing, try again after the index was updated
                if ($def !== null || $this->index->isComplete()) {
                    break;
                }
                yield waitForEvent($this->index, 'definition-added');
            }
            if (
                $def === null
                || $def->symbolInformation === null
                || Uri\parse($def->symbolInformation->location->uri)['scheme'] === 'phpstubs'
            ) {
                return [];
            }
            // if Definition is inside a dependency, use the package name
            $packageName = getPackageName($def->symbolInformation->location->uri, $this->composerJson);
            // else use the package name of the root package (if exists)
            if (!$packageName && $this->composerJson !== null) {
                $packageName = $this->composerJson->name;
            }
            $descriptor = new SymbolDescriptor($def->fqn, new PackageDescriptor($packageName));
            return [new SymbolLocationInformation($descriptor, $def->symbolInformation->location)];
        }); */
    }
}
