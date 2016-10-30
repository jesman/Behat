<?php

/*
 * This file is part of the Behat.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\Behat\Transformation\Transformer;

use Behat\Behat\Definition\Call\DefinitionCall;
use Behat\Behat\Definition\Pattern\PatternTransformer;
use Behat\Behat\Transformation\SimpleArgumentTransformation;
use Behat\Behat\Transformation\Transformation\PatternTransformation;
use Behat\Behat\Transformation\RegexGenerator;
use Behat\Behat\Transformation\Transformation;
use Behat\Behat\Transformation\TransformationRepository;
use Behat\Gherkin\Node\ArgumentInterface;
use Behat\Testwork\Call\CallCenter;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Argument transformer based on transformations repository.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
final class RepositoryArgumentTransformer implements ArgumentTransformer, RegexGenerator
{
    /**
     * @var TransformationRepository
     */
    private $repository;
    /**
     * @var CallCenter
     */
    private $callCenter;
    /**
     * @var PatternTransformer
     */
    private $patternTransformer;
    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * Initializes transformer.
     *
     * @param TransformationRepository $repository
     * @param CallCenter               $callCenter
     * @param PatternTransformer       $patternTransformer
     * @param TranslatorInterface      $translator
     */
    public function __construct(
        TransformationRepository $repository,
        CallCenter $callCenter,
        PatternTransformer $patternTransformer,
        TranslatorInterface $translator
    ) {
        $this->repository = $repository;
        $this->callCenter = $callCenter;
        $this->patternTransformer = $patternTransformer;
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDefinitionAndArgument(DefinitionCall $definitionCall, $argumentIndex, $argumentValue)
    {
        return count($this->repository->getEnvironmentTransformations($definitionCall->getEnvironment())) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function transformArgument(DefinitionCall $definitionCall, $argumentIndex, $argumentValue)
    {
        $environment = $definitionCall->getEnvironment();
        $transformations = $this->repository->getEnvironmentTransformations($environment);

        $newValue = $this->applySimpleTransformations($definitionCall, $transformations, $argumentIndex, $argumentValue);
        $newValue = $this->applyNormalTransformations($definitionCall, $transformations, $argumentIndex, $newValue);

        return $newValue;
    }

    /**
     * Transforms argument value using registered transformers.
     *
     * @param Transformation $transformation
     * @param DefinitionCall $definitionCall
     * @param integer|string $index
     * @param mixed          $value
     *
     * @return mixed
     */
    private function transform(DefinitionCall $definitionCall, Transformation $transformation, $index, $value)
    {
        if (is_object($value) && !$value instanceof ArgumentInterface) {
            return $value;
        }

        if ($transformation instanceof SimpleArgumentTransformation &&
            $transformation->supportsDefinitionAndArgument($definitionCall, $index, $value)) {
            return $transformation->transformArgument($this->callCenter, $definitionCall, $index, $value);
        }

        if ($transformation instanceof PatternTransformation &&
            $transformation->supportsDefinitionAndArgument($this, $definitionCall, $value)) {
            return $transformation->transformArgument($this, $this->callCenter, $definitionCall, $value);
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function generateRegex($suiteName, $pattern, $language)
    {
        $translatedPattern = $this->translator->trans($pattern, array(), $suiteName, $language);
        if ($pattern == $translatedPattern) {
            return $this->patternTransformer->transformPatternToRegex($pattern);
        }

        return $this->patternTransformer->transformPatternToRegex($translatedPattern);
    }

    /**
     * Apply simple argument transformations in priority order.
     *
     * @param DefinitionCall   $definitionCall
     * @param Transformation[] $transformations
     * @param integer|string   $index
     * @param mixed            $value
     *
     * @return mixed
     */
    private function applySimpleTransformations(DefinitionCall $definitionCall, array $transformations, $index, $value)
    {
        $simpleTransformations = array_filter($transformations, function ($transformation) {
            return $transformation instanceof SimpleArgumentTransformation;
        });

        usort($simpleTransformations, function (SimpleArgumentTransformation $t1, SimpleArgumentTransformation $t2) {
            if ($t1->getPriority() == $t2->getPriority()) {
                return 0;
            }

            return ($t1->getPriority() > $t2->getPriority()) ? -1 : 1;
        });

        $newValue = $value;
        foreach ($simpleTransformations as $transformation) {
            $newValue = $this->transform($definitionCall, $transformation, $index, $newValue);
        }

        return $newValue;
    }

    /**
     * Apply normal (non-simple) argument transformations.
     *
     * @param DefinitionCall   $definitionCall
     * @param Transformation[] $transformations
     * @param integer|string   $index
     * @param mixed            $value
     *
     * @return mixed
     */
    private function applyNormalTransformations(DefinitionCall $definitionCall, array $transformations, $index, $value)
    {
        $normalTransformations = array_filter($transformations, function ($transformation) {
            return !$transformation instanceof SimpleArgumentTransformation;
        });

        foreach ($normalTransformations as $transformation) {
            $value = $this->transform($definitionCall, $transformation, $index, $value);
        }

        return $value;
    }
}
