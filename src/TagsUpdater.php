<?php declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: brzuchal
 * Date: 18.12.16
 * Time: 12:43
 */

namespace Plumbok;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Method;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\Void_;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Plumbok\Compiler\NodeFinder;

/**
 * Class TagsInDocBlockFileWriter
 * @package Plumbok\Compiler\File
 * @author Michał Brzuchalski <michal.brzuchalski@gmail.com>
 */
class TagsUpdater
{
    /**
     * @var Parser
     */
    private $phpParser;
    /**
     * @var NodeFinder
     */
    private $nodeFinder;

    /**
     * TagsFileWriter constructor.
     * @param NodeFinder $nodeFinder
     * @internal param string $filename
     */
    public function __construct(NodeFinder $nodeFinder)
    {
        $this->phpParser = (new ParserFactory)->create(ParserFactory::ONLY_PHP7);
        $this->nodeFinder = $nodeFinder;
    }

    /**
     * @param string $filename
     * @param Node[] ...$generated
     */
    public function applyNodes(string $filename, Node ...$generated)
    {
        // open and lock for write
        $fp = fopen($filename, 'rw+');
        flock($fp, LOCK_EX);
        // read line positions for classes without doc comments
        $content = '';
        $line = 1;
        $lines = [];
        while (false == feof($fp)) {
            $content .= fgets($fp);
            $lines[$line++] = ftell($fp);
        }
        // parse original code
        $nodes = $this->phpParser->parse($content);
        // rewind and re-utilise $content
        fseek($fp, 0);
        $content = '';
        $originalNamespaces = $this->nodeFinder->findNamespaces(...$nodes);
        foreach ($originalNamespaces as $originalNamespace) {
            $generatedNamespace = $this->findNamespace((string)$originalNamespace->name, ...$generated);
            if (!$generatedNamespace) {
                continue;
            }
            $typeContext = new Context((string)$originalNamespace->name, $this->nodeFinder->findUses(...$originalNamespace->stmts));
            foreach ($this->nodeFinder->findClasses(...$originalNamespace->stmts) as $originalClass) {
                $generatedClass = $this->findClass($originalClass->name->name, ...$generatedNamespace->stmts);
                if (!$generatedClass) {
                    continue;
                }
                $comment = $this->createComment($originalClass, $generatedClass, $typeContext);
                /** @var Doc $doc */
                if ($doc = $originalClass->getDocComment()) {
                    $content .= fread($fp, $doc->getFilePos());
                    $content .= $comment;
                    fseek($fp, strlen($doc->getText()), SEEK_CUR);
                } else {
                    $line = $originalClass->getLine();
                    $content .= fread($fp, $lines[$line - 1]);
                    $content .= $comment . PHP_EOL;
                }
            }
        }
        if (!$originalNamespaces) {
            $typeContext = new Context('global', $this->nodeFinder->findUses(...$nodes));
            foreach ($this->nodeFinder->findClasses(...$nodes) as $originalClass) {
                $generatedClass = $this->findClass($originalClass->name->name, ...$generated);
                if (!$generatedClass) {
                    continue;
                }
                $comment = $this->createComment($originalClass, $generatedClass, $typeContext);
                /** @var Doc $doc */
                if ($doc = $originalClass->getDocComment()) {
                    $content .= fread($fp, $doc->getFilePos());
                    $content .= $comment;
                    fseek($fp, strlen($doc->getText()), SEEK_CUR);
                } else {
                    $line = $originalClass->getLine();
                    $content .= fread($fp, $lines[$line - 1]);
                    $content .= $comment . PHP_EOL;
                }
            }
        }
        // read tailed content
        $content .= fread($fp, filesize($filename) - ftell($fp));
        // rewind, truncate and write new content
        fseek($fp, 0);
        ftruncate($fp, 0);
        fwrite($fp, $content);
        // release and close
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * @param string $classDoc
     * @return string
     */
    public static function removeSpaceFromClassTags(string $classDoc): string
    {
        return preg_replace('/(@\S*)(\s*)(\(.*\)|\()/m', '$1$3', $classDoc);
    }

    /**
     * @param string $name
     * @param Node[] ...$nodes
     * @return Node\Stmt\Namespace_
     */
    private function findNamespace(string $name, Node ...$nodes)
    {
        foreach ($this->nodeFinder->findNamespaces(...$nodes) as $namespace) {
            if ((string)$namespace->name == $name) {
                return $namespace;
            }
        }

        return null;
    }

    /**
     * @param string $name
     * @param Node[] ...$nodes
     * @return Node\Stmt\Class_
     */
    private function findClass(string $name, Node ...$nodes)
    {
        foreach ($this->nodeFinder->findClasses(...$nodes) as $class) {
            if ($class->name->name == $name) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param Node\Stmt\Class_ $originalClass
     * @param Node\Stmt\Class_ $generatedClass
     * @param Context $context
     * @return mixed
     */
    private function createComment(Node\Stmt\Class_ $originalClass, Node\Stmt\Class_ $generatedClass, Context $context)
    {
        $summary = "Class {$originalClass->name->name}";
        $description = null;
        $tags = [];
        if ($docComment = $originalClass->getDocComment()) {
            $docBlock = DocBlockFactory::createInstance()->create((string)$docComment, $context);
            $summary = $docBlock->getSummary() ?? $summary;
            if (!empty((string)$docBlock->getDescription())) {
                $description = (string)$docBlock->getDescription();
            }
            $tags = $docBlock->getTags();
        }
        foreach ($tags as $index => $tag) {
            if ($tag instanceof Method) {
                unset($tags[$index]);
            }
        }
        $originalMethods = array_map(function (Node\Stmt\ClassMethod $method) {
            return $method->name->name;
        }, $originalClass->getMethods());
        foreach ($generatedClass->getMethods() as $method) {
            if (in_array($method->name->name, $originalMethods)) {
                continue;
            }
            $tags[] = $this->createMethodTag($method, $context);
        }

        return self::removeSpaceFromClassTags(
            str_replace(
                "/**\n * \n *\n",
                "/**\n",
                (new DocBlock\Serializer())->getDocComment(new DocBlock($summary, $description, $tags, $context))
            )
        );
    }

    /**
     * @param Node\Stmt\ClassMethod $method
     * @param Context $context
     * @return Method
     */
    private function createMethodTag(Node\Stmt\ClassMethod $method, Context $context): Method
    {
        $docBlock = DocBlockFactory::createInstance()->create((string)$method->getDocComment(), $context);
        $arguments = array_map(function (Param $param, int $key) use ($method) {
            $compound = null;
            $default = $method->getParams()[$key]->jsonSerialize()['default'];
            if (
                !empty($default)
                && !($param->getType() instanceof \phpDocumentor\Reflection\Types\Compound)
                && $default->jsonSerialize()['name']->toString() === 'null'
            ) {
                $compound = new \phpDocumentor\Reflection\Types\Compound([
                    $param->getType(),
                    new \phpDocumentor\Reflection\Types\Null_()
                ]);
            }

            return [
                'name' => $param->getVariableName(),
                'type' => $compound ?? $param->getType(),
            ];
        }, $docBlock->getTagsByName('param'), array_keys($docBlock->getTagsByName('param')));
        if ($docBlock->hasTag('return')) {
            /** @var Return_ $returnTag */
            $returnTag = $docBlock->getTagsByName('return')[0];
            $returnType = $returnTag->getType();
        } else {
            $returnType = new Void_();
        }

        return new Method($method->name->name, $arguments, $returnType, $method->isStatic());
    }
}
