<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link https://phpdoc.org
 */

namespace phpDocumentor\Guides\RestructuredText\Parser;

use ArrayObject;
use Doctrine\Common\EventManager;
use phpDocumentor\Guides\Nodes\DocumentNode;
use phpDocumentor\Guides\Nodes\Node;
use phpDocumentor\Guides\Nodes\SectionEndNode;
use phpDocumentor\Guides\Nodes\TitleNode;
use phpDocumentor\Guides\RestructuredText\Directives\Directive as DirectiveHandler;
use phpDocumentor\Guides\RestructuredText\Event\PostParseDocumentEvent;
use phpDocumentor\Guides\RestructuredText\Event\PreParseDocumentEvent;
use phpDocumentor\Guides\RestructuredText\Parser;

use function array_search;
use function md5;
use function trim;

class DocumentParser implements Productions\Rule
{
    /** @var Parser */
    private $parser;

    /** @var EventManager */
    private $eventManager;

    /** @var ArrayObject<int, DirectiveHandler> */
    private $directives;

    /** @var DocumentNode */
    private $document;

    /** @var bool public is temporary */
    public $nextIndentedBlockShouldBeALiteralBlock = false;

    /** @var DocumentIterator */
    private $documentIterator;

    /** @var ?TitleNode */
    public $lastTitleNode;

    /** @var ArrayObject<int, TitleNode> */
    public $openSectionsAsTitleNodes;

    /** @var array<int, Productions\Rule> */
    private $productions;

    /**
     * @param DirectiveHandler[] $directives
     */
    public function __construct(
        Parser $parser,
        EventManager $eventManager,
        array $directives
    ) {
        $this->parser = $parser;
        $this->eventManager = $eventManager;

        $this->documentIterator = new DocumentIterator();
        $this->openSectionsAsTitleNodes = new ArrayObject();
        $this->directives = new ArrayObject($directives);

        $lineDataParser = new LineDataParser($this->parser, $eventManager);

        $literalBlockRule = new Productions\LiteralBlockRule();
        $this->productions = [
            new Productions\TitleRule($this->parser, $this),
            new Productions\TransitionRule(), // Transition rule must follow Title rule
            new Productions\LinkRule($lineDataParser, $parser->getEnvironment()),
            $literalBlockRule,
            new Productions\BlockQuoteRule($parser),
            new Productions\ListRule($lineDataParser, $parser->getEnvironment()),
            new Productions\DirectiveRule($parser, $this, $lineDataParser, $literalBlockRule, $directives),
            new Productions\CommentRule(),
            new Productions\DefinitionListRule($lineDataParser),
            new Productions\TableRule($parser, $eventManager),

            // For now: ParagraphRule must be last as it is the rule that applies if none other applies.
            new Productions\ParagraphRule($this->parser, $this),
        ];
    }

    public function applies(DocumentParser $documentParser): bool
    {
        return true;
    }

    public function apply(DocumentIterator $documentIterator): ?Node
    {
        foreach ($this->productions as $production) {
            if (!$production->applies($this)) {
                continue;
            }

            $newNode = $production->apply($this->documentIterator);
            if ($newNode !== null) {
                $this->document->addNode($newNode);
            }

            break;
        }

        return $this->document;
    }

    public function parse(string $contents): DocumentNode
    {
        $preParseDocumentEvent = new PreParseDocumentEvent($this->parser, $contents);

        $this->eventManager->dispatchEvent(
            PreParseDocumentEvent::PRE_PARSE_DOCUMENT,
            $preParseDocumentEvent
        );

        $this->document = new DocumentNode(md5($contents));
        $this->parseLines(trim($preParseDocumentEvent->getContents()));

        $this->eventManager->dispatchEvent(
            PostParseDocumentEvent::POST_PARSE_DOCUMENT,
            new PostParseDocumentEvent($this->document)
        );

        return $this->document;
    }

    public function getDocument(): DocumentNode
    {
        return $this->document;
    }

    private function parseLines(string $document): void
    {
        $this->lastTitleNode = null;
        $this->openSectionsAsTitleNodes->exchangeArray([]); // clear it

        $this->documentIterator->load($this->parser->getEnvironment(), $document);

        // We explicitly do not use foreach, but rather the cursors of the DocumentIterator
        // this is done because we are transitioning to a method where a Substate can take the current
        // cursor as starting point and loop through the cursor
        while ($this->documentIterator->valid()) {
            $this->apply($this->documentIterator);

            $this->documentIterator->next();
        }

        // TODO: Can we get rid of this here? It would make this parser cleaner and if it is part of the
        //       Title/SectionRule itself it is neatly encapsulated.
        foreach ($this->openSectionsAsTitleNodes as $titleNode) {
            $this->endOpenSection($titleNode);
        }

        // TODO: Can we get rid of this here? It would make this parser cleaner and if it is part of the DirectiveRule
        //       itself it is neatly encapsulated.
        foreach ($this->directives as $directive) {
            $directive->finalize($this->document);
        }
    }

    public function endOpenSection(TitleNode $titleNode): void
    {
        $this->document->addNode(new SectionEndNode($titleNode));

        $key = array_search($titleNode, $this->openSectionsAsTitleNodes->getArrayCopy(), true);

        if ($key === false) {
            return;
        }

        unset($this->openSectionsAsTitleNodes[$key]);
    }

    public function getDocumentIterator(): DocumentIterator
    {
        return $this->documentIterator;
    }
}
