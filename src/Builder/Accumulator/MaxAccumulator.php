<?php

/**
 * THIS FILE IS AUTO-GENERATED. ANY CHANGES WILL BE LOST!
 */

declare(strict_types=1);

namespace MongoDB\Builder\Accumulator;

use MongoDB\BSON\Type;
use MongoDB\Builder\Type\AccumulatorInterface;
use MongoDB\Builder\Type\Encode;
use MongoDB\Builder\Type\ExpressionInterface;
use MongoDB\Builder\Type\OperatorInterface;
use MongoDB\Builder\Type\WindowInterface;
use stdClass;

/**
 * Returns the maximum value that results from applying an expression to each document.
 * Changed in MongoDB 5.0: Available in the $setWindowFields stage.
 *
 * @see https://www.mongodb.com/docs/manual/reference/operator/aggregation/max/
 */
class MaxAccumulator implements AccumulatorInterface, WindowInterface, OperatorInterface
{
    public const ENCODE = Encode::Single;

    /** @var ExpressionInterface|Type|array|bool|float|int|null|stdClass|string $expression */
    public readonly Type|ExpressionInterface|stdClass|array|bool|float|int|null|string $expression;

    /**
     * @param ExpressionInterface|Type|array|bool|float|int|null|stdClass|string $expression
     */
    public function __construct(Type|ExpressionInterface|stdClass|array|bool|float|int|null|string $expression)
    {
        $this->expression = $expression;
    }

    public function getOperator(): string
    {
        return '$max';
    }
}
