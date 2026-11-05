<?php

namespace App\Modules\Selection\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\DivisionByZeroException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The admin's formula for a stage result from panel members' totals (Stage A, D26), for example
 * `AVG`, `(SUM - MAX - MIN) / (COUNT - 2)` or `MEDIAN`. It is read by this small parser and never run as code:
 * only the words SUM, COUNT, AVG, MIN, MAX, MEDIAN, numbers, + - * / and brackets are understood.
 * Arithmetic is exact (brick/math); the result is rounded half up to 2 decimals.
 */
class StageFormula
{
    public const WORDS = ['SUM', 'COUNT', 'AVG', 'MIN', 'MAX', 'MEDIAN'];

    private array $tokens = [];

    private int $pos = 0;

    /** @return string|null an error message, or null when the formula is valid */
    public static function check(string $formula): ?string
    {
        try {
            (new self)->parse($formula);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param  list<string>  $totals  each member's total, as decimal strings
     * @return string the stage result, 2 decimals
     *
     * @throws DivisionByZeroException when the formula divides by zero for these totals
     */
    public static function evaluate(string $formula, array $totals): string
    {
        $vars = self::variables($totals);
        $ast = (new self)->parse($formula);

        return (string) self::run($ast, $vars)->toScale(2, RoundingMode::HalfUp);
    }

    /** @return array<string, BigDecimal> */
    public static function variables(array $totals): array
    {
        $d = array_map(fn ($t) => BigDecimal::of((string) $t), $totals);
        usort($d, fn ($a, $b) => $a->compareTo($b));
        $n = count($d);
        $sum = array_reduce($d, fn ($s, $x) => $s->plus($x), BigDecimal::zero());
        $median = $n === 0 ? BigDecimal::zero() : ($n % 2 ? $d[intdiv($n, 2)] : $d[$n / 2 - 1]->plus($d[$n / 2])->dividedBy(2, 12, RoundingMode::HalfUp));

        return [
            'SUM' => $sum, 'COUNT' => BigDecimal::of($n),
            'AVG' => $n ? $sum->dividedBy($n, 12, RoundingMode::HalfUp) : BigDecimal::zero(),
            'MIN' => $n ? $d[0] : BigDecimal::zero(), 'MAX' => $n ? $d[$n - 1] : BigDecimal::zero(), 'MEDIAN' => $median,
        ];
    }

    // ----- Parser: expr := term (('+'|'-') term)* ; term := factor (('*'|'/') factor)* ; factor := number | word | '(' expr ')' | '-' factor

    private function parse(string $formula): array
    {
        if (mb_strlen($formula) > 255) {
            throw new InvalidArgumentException('The formula is too long.');
        }
        if (! preg_match_all('/\s*(\d+(?:\.\d+)?|[A-Za-z]+|[()+\-*\/]|\S)/', $formula, $m)) {
            throw new InvalidArgumentException('The formula is empty.');
        }
        $this->tokens = $m[1];
        $this->pos = 0;
        $ast = $this->expr();
        if ($this->pos < count($this->tokens)) {
            throw new InvalidArgumentException("Unexpected \"{$this->tokens[$this->pos]}\".");
        }

        return $ast;
    }

    private function expr(): array
    {
        $node = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->tokens[$this->pos++];
            $node = ['op', $op, $node, $this->term()];
        }

        return $node;
    }

    private function term(): array
    {
        $node = $this->factor();
        while (in_array($this->peek(), ['*', '/'], true)) {
            $op = $this->tokens[$this->pos++];
            $node = ['op', $op, $node, $this->factor()];
        }

        return $node;
    }

    private function factor(): array
    {
        $t = $this->peek();
        if ($t === null) {
            throw new InvalidArgumentException('The formula ends too early.');
        }
        $this->pos++;
        if ($t === '(') {
            $node = $this->expr();
            if ($this->peek() !== ')') {
                throw new InvalidArgumentException('A bracket is not closed.');
            }
            $this->pos++;

            return $node;
        }
        if ($t === '-') {
            return ['op', '-', ['num', '0'], $this->factor()];
        }
        if (preg_match('/^\d+(\.\d+)?$/', $t)) {
            return ['num', $t];
        }
        if (in_array(strtoupper($t), self::WORDS, true)) {
            return ['var', strtoupper($t)];
        }

        throw new InvalidArgumentException("\"$t\" is not allowed. Use ".implode(', ', self::WORDS).', numbers, + - * / and brackets.');
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private static function run(array $n, array $vars): BigDecimal
    {
        return match ($n[0]) {
            'num' => BigDecimal::of($n[1]),
            'var' => $vars[$n[1]],
            'op' => match ($n[1]) {
                '+' => self::run($n[2], $vars)->plus(self::run($n[3], $vars)),
                '-' => self::run($n[2], $vars)->minus(self::run($n[3], $vars)),
                '*' => self::run($n[2], $vars)->multipliedBy(self::run($n[3], $vars)),
                '/' => self::run($n[2], $vars)->dividedBy(self::run($n[3], $vars), 12, RoundingMode::HalfUp),
            },
        };
    }
}
