<?php

namespace Czbd\CourierChecker\Helpers;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Class CourierDataValidator
 *
 * Provides helper methods for environment, configuration, and phone number validation.
 * Centralizes common checks used across different courier service classes.
 *
 * @package Czbd\CourierChecker\Helpers
 */
class CourierDataValidator
{
    /**
     * Verifies that the given environment variables exist.
     *
     * @param array $variables
     * @throws InvalidArgumentException
     */
    public static function enforceEnv(array $variables): void
    {
        foreach ($variables as $var) {
            if (empty(env($var))) {
                throw new InvalidArgumentException(sprintf("The environment variable %s is required but missing.", $var));
            }
        }
    }

    /**
     * Validates whether the given string is a proper Bangladeshi mobile number.
     *
     * @param string $mobileNumber
     * @throws InvalidArgumentException
     */
    public static function checkBdMobile(string $mobileNumber): void
    {
        $validation = Validator::make(
            ['mobile' => $mobileNumber],
            [
                'mobile' => [
                    'required',
                    'regex:/^01[3-9][0-9]{8}$/'
                ]
            ],
            [
                'mobile.regex' => 'The provided phone number is invalid. Please format it locally (e.g., 01*********) without +88.'
            ]
        );

        if ($validation->fails()) {
            throw new InvalidArgumentException($validation->errors()->first('mobile'));
        }
    }

    /**
     * Verifies that the given config keys are populated.
     *
     * @param array $configDefinitions
     * @throws InvalidArgumentException
     */
    public static function enforceConfig(array $configDefinitions): void
    {
        foreach ($configDefinitions as $definition) {
            $keys = is_array($definition) ? $definition : [$definition];

            if (self::firstFilledConfig($keys) === null) {
                throw new InvalidArgumentException(sprintf(
                    "At least one of these config keys is required but missing: %s.",
                    implode(', ', $keys)
                ));
            }
        }
    }

    /**
     * Returns the first non-empty value from a list of config keys.
     */
    public static function firstFilledConfig(array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = config($key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Parse comma or newline separated values into a clean array.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    public static function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string)($value ?? ''));
            if ($raw === '') {
                return [];
            }

            $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $entry = trim((string)$item);
            if ($entry !== '') {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * Build credential pool using a primary account and optional list-based fallback accounts.
     *
     * @param string $courier
     * @param array $singleCredential
     * @param array $listCredentials
     * @return array<int, array<string, string>>
     */
    public static function buildCredentialPool(string $courier, array $singleCredential, array $listCredentials): array
    {
        $fields = array_values(array_unique(array_merge(array_keys($singleCredential), array_keys($listCredentials))));

        if ($fields === []) {
            throw new InvalidArgumentException(sprintf('No credential fields defined for %s.', $courier));
        }

        $pool = [];

        $singleRow = [];
        $singleComplete = true;
        foreach ($fields as $field) {
            $value = trim((string)($singleCredential[$field] ?? ''));
            $singleRow[$field] = $value;
            if ($value === '') {
                $singleComplete = false;
            }
        }

        if ($singleComplete) {
            $pool[] = $singleRow;
        }

        $parsedLists = [];
        $listCount = 0;
        foreach ($fields as $field) {
            $parsedLists[$field] = self::parseList($listCredentials[$field] ?? '');
            $listCount = max($listCount, count($parsedLists[$field]));
        }

        if ($listCount > 0) {
            foreach ($fields as $field) {
                $count = count($parsedLists[$field]);
                if ($count !== $listCount) {
                    throw new InvalidArgumentException(sprintf(
                        '%s account lists are misaligned for field %s. Ensure each list has the same number of values.',
                        $courier,
                        $field
                    ));
                }
            }

            for ($i = 0; $i < $listCount; $i++) {
                $row = [];
                foreach ($fields as $field) {
                    $value = trim((string)$parsedLists[$field][$i]);
                    if ($value === '') {
                        throw new InvalidArgumentException(sprintf(
                            '%s account list contains empty value at position %d for field %s.',
                            $courier,
                            $i + 1,
                            $field
                        ));
                    }

                    $row[$field] = $value;
                }

                $pool[] = $row;
            }
        }

        $uniquePool = [];
        foreach ($pool as $credential) {
            $keyParts = [];
            foreach ($fields as $field) {
                $keyParts[] = $credential[$field] ?? '';
            }

            $hash = implode('|', $keyParts);
            $uniquePool[$hash] = $credential;
        }

        $finalPool = array_values($uniquePool);

        if ($finalPool === []) {
            throw new InvalidArgumentException(sprintf(
                'No valid credentials configured for %s. Set primary keys or list-based accounts.',
                $courier
            ));
        }

        return $finalPool;
    }

    /**
     * Parse yes/no config flag. Empty behaves like no.
     */
    protected static function parseYesNoFlag(string $configKey): bool
    {
        $raw = strtolower(trim((string) config($configKey, 'no')));

        if ($raw === '' || $raw === 'no') {
            return false;
        }

        if ($raw === 'yes') {
            return true;
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid value for %s. Allowed values are yes or no.',
            $configKey
        ));
    }

    /**
     * Resolve proxy for a courier using yes/no flags and shared address.
     */
    public static function resolveCourierProxy(string $courier): ?string
    {
        $address = trim((string) config('courier-checker.proxy.address', ''));
        if ($address === '') {
            return null;
        }

        $globalEnabled = self::parseYesNoFlag('courier-checker.proxy.all');
        $specificEnabled = self::parseYesNoFlag("courier-checker.proxy.{$courier}");

        if ($globalEnabled || $specificEnabled) {
            return $address;
        }

        return null;
    }

    /**
     * Return HTTP client proxy options for both HTTP and HTTPS.
     */
    public static function proxyOptions(?string $proxy): array
    {
        $value = trim((string)$proxy);
        if ($value === '') {
            return [];
        }

        return [
            'proxy' => [
                'http' => $value,
                'https' => $value,
            ],
        ];
    }
}
