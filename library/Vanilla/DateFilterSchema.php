<?php
/**
 * @copyright 2009-2017 Vanilla Forums Inc.
 * @license GNU GPLv2
 */

namespace Vanilla;

use DateTimeImmutable;
use Garden\Schema\Invalid;
use Garden\Schema\Schema;
use Garden\Schema\ValidationField;
use Garden\Schema\ValidationException;

class DateFilterSchema extends Schema {

    /** @var string Regular expression for matching a datetime. */
    private $dateRegEx = '(?<date>\d{4}-\d{2}-\d{2})(?<time>[ T]\d{2}:\d{2}(:\d{2})?)?';

    /** @var array Valid characters for opening an interval-notation range. */
    private $intervalOpen = ['(', '['];

    /** @var array Valid characters for closing an interval-notation range. */
    private $intervalClose = [')', ']'];

    /** @var array Valid operators for simple date comparisons. */
    private $simpleOperators = ['=', '>', '<', '>=', '<='];

    /**
     * {@inheritdoc}
     */
    public function __construct(array $schema = []) {
        if (!empty($schema)) {
            throw new \InvalidArgumentException(self::class.' does not support custom schemas.');
        }

        parent::__construct([
            'type' => 'object',
            'properties' => [
                    'op:s' => [
                        'enum' => ['=', '>', '<', '<=', '>=', '[]', '()', '[)', '(]']
                ],
                'value' => [
                    'type' => ['datetime', 'array'],
                    'items' => [
                        'type' => 'datetime'
                    ],
                    'minItems' => 1,
                    'maxItems' => 2
                ]
            ]
        ]);
    }

    /**
     * Parse an interval-notation date range into an array representation.
     *
     * @param string $dates
     * @param string $open
     * @param string $close
     * @param ValidationField $field
     * @return array|string
     */
    private function parseInterval($dates, $open, $close, ValidationField $field) {
        // Quick sanity check on the values...
        if (!in_array($open, $this->intervalOpen) || !in_array($close, $this->intervalClose)) {
            return Invalid::value();
        } elseif (!is_string($dates)) {
            return Invalid::value();
        }

        // This notation only allows two dates, specifically.
        $dateArray = explode(',', $dates);
        if (count($dateArray) != 2) {
            return Invalid::value();
        }
        array_walk($dateArray, 'trim');

        if (!preg_match("/{$this->dateRegEx}/i", $dateArray[0], $beginParts) ||
            !preg_match("/{$this->dateRegEx}/i", $dateArray[1], $endParts)
        ) {
            $field->addTypeError('datetime');
            return Invalid::value();
        }

        // Convert strings to datetime objects.
        /** @var DateTimeImmutable[] $dateTimeArray */
        $dateTimeArray = [];
        try {
            $dateTimeArray[0] = new DateTimeImmutable($dateArray[0]);
            $dateTimeArray[1] = new DateTimeImmutable($dateArray[1]);
        } catch (\Exception $e) {
            $field->addTypeError('datetime');
            return Invalid::value();
        }

        // Adjust the beginning of the range to account for exclusive specifications.
        if ($open == '(') {
            if (array_key_exists('time', $beginParts)) {
                $dateTimeArray[0] = $dateTimeArray[0]->modify('+1 second');
            } else {
                $dateTimeArray[0] = $dateTimeArray[0]->modify('+1 day');
            }
        }

        // Adjust the closing of the range to account for inclusive and exclusive specifications.
        if ($close == ']' && !array_key_exists('time', $endParts)) {
            $dateTimeArray[1] = $dateTimeArray[1]->modify('+1 day')->modify('-1 second');
        } elseif ($close == ')') {
            $dateTimeArray[1] = $dateTimeArray[1]->modify('-1 second');
        }

        $result = [
            'op' => $open.$close,
            'value' => $dateTimeArray
        ];
        return $result;
    }

    /**
     * Parse a simple date comparison string into an array representation.
     *
     * @param string $date
     * @param string $operator
     * @param ValidationField $field
     * @return array|string
     */
    public function parseSimple($date, $operator, ValidationField $field) {
        if ($operator == '') {
            $operator = '=';
        }

        // Sanity check on the parameters...
        if (!is_string($date) || !in_array($operator, $this->simpleOperators)) {
            return Invalid::value();
        }

        if (!preg_match("/{$this->dateRegEx}/i", $date, $dateTimeParts)) {
            $field->addTypeError('datetime');
            return Invalid::value();
        }

        try {
            $dateTime = new DateTimeImmutable($date);
        } catch (\Exception $e) {
            $field->addTypeError('datetime');
            return Invalid::value();
        }

        // If all we have is a date, give us a range in that date.
        if ($operator == '=' && !array_key_exists('time', $dateTimeParts)) {
            $dateTime = [
                $dateTime,
                $dateTime->modify('+1 day')->modify('-1 second')
            ];
        }

        $result = [
            'op' => $operator,
            'value' => $dateTime
        ];
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function validate($data, $sparse = false) {
        $field = new ValidationField($this->createValidation(), $this->getSchemaArray(), '', $sparse);

        $clean = $this->validateDateFilter($data, $field);

        if (Invalid::isInvalid($clean) && $field->isValid()) {
            // This really shouldn't happen, but we want to protect against seeing the invalid object.
            $field->addError('invalid', ['messageCode' => '{field} is invalid.', 'status' => 422]);
        }

        if (!$field->getValidation()->isValid()) {
            throw new ValidationException($field->getValidation());
        }

        return $clean;
    }

    /**
     * Validate a date filter.
     *
     * @param mixed $value The value to validate.
     * @param ValidationField $field The validation results to add.
     * @return string|Invalid Returns the valid string or **null** if validation fails.
     */
    private function validateDateFilter($value, ValidationField $field) {
        $result = Invalid::value();

        if (is_string($value)) {
            $first = substr($value, 0, 1);
            if (in_array($first, $this->intervalOpen)) {
                $last = substr($value, -1, 1);
                if (in_array($last, $this->intervalClose) && strlen($value) > 2) {
                    $dates = substr($value, 1, -1);
                    $result = $this->parseInterval($dates, $first, $last, $field);
                }
            } elseif (preg_match('/^(?<op><=|>=|>|<|)?\s*(?<value>'.$this->dateRegEx.')/i', $value, $match)) {
                $result = $this->parseSimple($match['value'], $match['op'], $field);
            }
        }

        return $result;
    }
}
