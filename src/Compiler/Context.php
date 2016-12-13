<?php
/**
 * Created by PhpStorm.
 * User: brzuchal
 * Date: 12.12.16
 * Time: 18:35
 */
namespace Plumbok\Compiler;

use Plumbok\Annotation\AllArgsConstructor;
use Plumbok\Annotation\Data;
use Plumbok\Annotation\Equal;
use Plumbok\Annotation\NoArgsConstructor;
use Plumbok\Annotation\RequiredArgsConstructor;
use Plumbok\Annotation\Value;

/**
 * Class Context
 * @package Plumbok\Compiler
 * @author Michał Brzuchalski <m.brzuchalski@madkom.pl>
 */
class Context
{
    /**
     * @var array Holds applied annotations
     */
    private $applied = [];
    /**
     * @var array Holds self excluding annotations
     */
    private static $excludingAnnotations = [
        'generic' => [
            Value::class,
            Data::class,
        ],
        'constructor' => [
            AllArgsConstructor::class,
            NoArgsConstructor::class,
            RequiredArgsConstructor::class,
        ],
    ];
    /**
     * @var bool Holds all argument constructor creation flag
     */
    private $allArgsConstructor = false;
    /**
     * @var bool Holds no argument constructor creation flag
     */
    private $noArgsConstructor = false;
    /**
     * @var bool Holds only required arguments constructor creation flag
     */
    private $requiredArgsConstructor = false;
    /**
     * @var bool Holds all properties getter creation flag
     */
    private $allPropertyGetters = false;
    /**
     * @var bool Holds all properties setter creation flag
     */
    private $allPropertySetters = false;
    /**
     * @var bool Holds equality comparator creation flag
     */
    private $equal = false;

    /**
     * Context constructor.
     * @param array $annotations
     */
    public function __construct(array $annotations)
    {
        foreach ($annotations as $annotation) {
            switch (get_class($annotation)) {
                case Value::class:
                case Data::class:
                case AllArgsConstructor::class:
                case NoArgsConstructor::class:
                case RequiredArgsConstructor::class:
                case Equal::class:
                    if ($this->checkNonExcludingUsage($annotation)) {
                        $this->apply($annotation);
                    }
                    break;
            }
        }
    }

    private function applyValue($annotation)
    {
        $this->allArgsConstructor = true;
        $this->allPropertyGetters = true;
        $this->equal = true;
    }

    private function applyData($annotation)
    {
        $this->requiredArgsConstructor = true;
        $this->allPropertyGetters = true;
        $this->allPropertySetters = true;
    }

    private function applyAllArgsConstructor($annotation)
    {
        $this->allArgsConstructor = true;
        $this->noArgsConstructor = false;
        $this->requiredArgsConstructor = false;
    }

    private function applyNoArgsConstructor($annotation)
    {
        $this->noArgsConstructor = true;
        $this->allArgsConstructor = false;
        $this->requiredArgsConstructor = false;
    }

    private function applyRequiredArgsConstructor($annotation)
    {
        $this->requiredArgsConstructor = true;
        $this->allArgsConstructor = false;
        $this->noArgsConstructor = false;
    }

    private function applyEqual($annotation)
    {
        $this->equal = true;
    }

    /**
     * @param $annotation
     * @throws \UnexpectedValueException When unsupported annotation applied
     * @uses applyValue
     * @uses applyData
     * @uses applyAllArgsConstructor
     * @uses applyNoArgsConstructor
     * @uses applyRequiredArgsConstructor
     * @uses applyEqual
     */
    private function apply($annotation)
    {
        $name = str_replace('Plumbok\\Annotation\\', '', get_class($annotation));
        $method = "apply{$name}";
        if (!method_exists($this, $method)) {
            throw new \UnexpectedValueException("Unsupported annotation applied, given: {$name}");
        }
        $this->{$method}($annotation);
    }

    /**
     * @param $annotation
     * @return bool
     * @throws \UnexpectedValueException When detected excluding annotation already applied
     */
    private function checkNonExcludingUsage($annotation) : bool
    {
        $class = get_class($annotation);
        $appliedAnnotations = array_filter(array_map('get_class', $this->applied));
        foreach (self::$excludingAnnotations as $groupName => $excludingAnnotations) {
            if (
                in_array($class, $excludingAnnotations)
                && count(array_intersect($excludingAnnotations, $appliedAnnotations))
            ) {
                throw new \UnexpectedValueException("Cannot use {$class} annotation because already applied excluding one");
            }
        }

        return true;
    }

    /**
     * @return boolean
     */
    public function isAllArgsConstructor(): bool
    {
        return $this->allArgsConstructor;
    }

    /**
     * @return boolean
     */
    public function isNoArgsConstructor(): bool
    {
        return $this->noArgsConstructor;
    }

    /**
     * @return boolean
     */
    public function isRequiredArgsConstructor(): bool
    {
        return $this->requiredArgsConstructor;
    }

    /**
     * @return boolean
     */
    public function isAllPropertyGetters(): bool
    {
        return $this->allPropertyGetters;
    }

    /**
     * @return boolean
     */
    public function isAllPropertySetters(): bool
    {
        return $this->allPropertySetters;
    }

    /**
     * @return boolean
     */
    public function isEqual(): bool
    {
        return $this->equal;
    }
}
