<?php

namespace Spatie\LaravelData\Support\Validation;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Validation\InvokableRule as InvokableRuleContract;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\LaravelData\Attributes\Validation\ObjectValidationAttribute;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\StringValidationAttribute;
use Spatie\LaravelData\Support\Validation\References\FieldReference;
use Spatie\LaravelData\Support\Validation\References\RouteParameterReference;

class RuleDenormalizer
{
    /** @return array<string|object|RuleContract|InvokableRuleContract> */
    public function execute(mixed $rule, ValidationPath $path): array
    {
        if (is_string($rule)) {
            if (Str::contains($rule, 'regex:')) {
                return [$rule];
            }

            $oneField = ['different', 'exclude_with', 'exclude_without', 'gt', 'gte', 'lt', 'lte', 'same'];
            $fieldWithValue = ['accepted_if', 'declined_if', 'exclude_if', 'exclude_unless', 'missing_if', 'missing_unless', 'prohibited_if', 'prohibited_unless', 'required_if', 'required_unless'];
            $manyFields = ['missing_with', 'missing_with_all', 'prohibits', 'required_with', 'required_with_all', 'required_without', 'required_without_all'];

            return collect(explode('|', $rule))
                ->map(function (string $item) use ($path, $oneField, $fieldWithValue, $manyFields) {
                    $arr = explode(':', $item);
                    if (!isset($arr[1])) {
                        return $item;
                    }
                    if (in_array($arr[0], array_merge($oneField, $fieldWithValue))) {
                        $arr[1] = $this->addPath($path, $arr[1]);
                    }
                    if (in_array($arr[0], $manyFields)) {
                        $tmp = explode(',', $arr[1]);
                        $tmp = array_map(fn ($s) => $this->addPath($path, $s), $tmp);
                        $arr[1] = implode(',', $tmp);
                    }

                    return implode(':', $arr);
                })
                ->all();
        }

        if (is_array($rule)) {
            return Arr::flatten(array_map(
                fn (mixed $rule) => $this->execute($rule, $path),
                $rule
            ));
        }

        if ($rule instanceof StringValidationAttribute) {
            return $this->normalizeStringValidationAttribute($rule, $path);
        }

        if ($rule instanceof ObjectValidationAttribute) {
            return [$rule->getRule($path)];
        }

        if ($rule instanceof Rule) {
            return $this->execute($rule->get(), $path);
        }

        if ($rule instanceof RuleContract || $rule instanceof InvokableRuleContract) {
            return [$rule];
        }

        return [$rule];
    }

    protected function addPath(ValidationPath $path, string $name): string
    {
        $prepend = $path->get();

        return ($prepend === null | $prepend == '') ? $name : $prepend . '.' . $name;
    }

    protected function normalizeStringValidationAttribute(
        StringValidationAttribute $rule,
        ValidationPath $path
    ): array {
        $parameters = collect($rule->parameters())
            ->map(fn (mixed $value) => $this->normalizeRuleParameter($value, $path))
            ->reject(fn (mixed $value) => $value === null);


        if ($parameters->isEmpty()) {
            return [$rule->keyword()];
        }

        $parameters = $parameters->map(
            fn (mixed $value, int|string $key) => is_string($key) ? "{$key}={$value}" : $value
        );

        return ["{$rule->keyword()}:{$parameters->join(',')}"];
    }

    protected function normalizeRuleParameter(
        mixed $parameter,
        ValidationPath $path
    ): ?string {
        if ($parameter === null) {
            return null;
        }

        if (is_string($parameter) || is_numeric($parameter)) {
            return (string) $parameter;
        }

        if (is_bool($parameter)) {
            return $parameter ? 'true' : 'false';
        }

        if (is_array($parameter) && count($parameter) === 0) {
            return null;
        }

        if (is_array($parameter)) {
            $subParameters = array_map(
                fn (mixed $subParameter) => $this->normalizeRuleParameter($subParameter, $path),
                $parameter
            );

            return implode(',', $subParameters);
        }

        if ($parameter instanceof DateTimeInterface) {
            return $parameter->format(DATE_ATOM);
        }

        if ($parameter instanceof BackedEnum) {
            return $parameter->value;
        }

        if ($parameter instanceof FieldReference) {
            return $parameter->getValue($path);
        }

        if ($parameter instanceof RouteParameterReference) {
            return $this->normalizeRuleParameter($parameter->getValue(), $path);
        }

        return (string) $parameter;
    }
}
