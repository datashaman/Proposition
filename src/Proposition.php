<?php
namespace Proposition;

use Generator;

class Proposition
{
    /** Default maximum number of tests to run. */
    const DEFAULT_MAX_TESTS = 100;

    /** Default chunk size to use for reshuffling generators. It's kind of low, since the memory footprint from each
     *  generator is multiplied by approx. this value */
    const DEFAULT_RESHUFFLE_CHUNK_SIZE = 10;

    /** @var int Maximum number of tests to run. */
    private $max_tests;

    /** @var Generator[] An array of generators representing a "given". */
    private $generators = [];

    /**
     * Construct a Proposition object.
     *
     * @param int $max_tests
     */
    public function __construct(
        $max_tests = self::DEFAULT_MAX_TESTS,
        $reshuffle_chunk_size = self::DEFAULT_RESHUFFLE_CHUNK_SIZE
    )
    {
        $this->max_tests = (int)$max_tests;
        $this->reshuffle_chunk_size = (int)$reshuffle_chunk_size;
    }

    /**
     * Add the given $generators to the list of generators which will be used to construct arguments.
     *
     * For example, if you pass ONE generator into given(), the $hypothesis that you pass into call($hypothesis) should
     * be a callable which takes a single argument. The value of that argument will be generated by the generator you
     * passed into $given. And if given() is called with TWO generators, the value of $hypothesis should take TWO
     * arguments: the value of the first argument is generated by the first generator, the value of the second argument
     * is generated by the second generator. And so on.
     *
     * You can of course call given() multiple times if you need to. The generators are simply appended one after the
     * other.
     *
     * @param Generator ...$generators which generate values.
     *
     * @return $this to allow chaining methods.
     */
    public function given(...$generators)
    {
        foreach ($generators as &$generator) {
            if (is_array($generator)) {
                $generator = self::combine(...$generator);
            }

            $generator = self::reshuffle($generator, $this->reshuffle_chunk_size);
        }
        array_push($this->generators, ...$generators);

        return $this;
    }

    /**
     * Run the given $hypothesis function with values generated by whatever you passed into given(). Optionally return
     * an array containing the arguments that were passed into the $hypothesis, along with the value that $hypothesis
     * returned for those arguments.
     *
     * @param callable $hypothesis    A function which takes values generated by whatever you passed into given(), and
     *                                e.g. asserts that some property of those values always holds. The function can
     *                                return whatever you want.
     *
     * @param bool     $return_values Whether to store and return whatever $hypothesis returns.
     *
     * @return Proposition|array      If $return_values is true, it will be an array of arrays with two entries:
     *                                'argument' and 'result'. Each 'arguments' is an array with the arguments that
     *                                Proposition tried to pass into the $hypothesis, and 'result' is whatever the
     *                                $hypothesis returned.
     *
     *                                Otherwise, $this is returned.
     */
    public function call(callable $hypothesis, $return_values = false)
    {
        $results = [];

        foreach ($this->generateArgumentLists() as $argument_list) {
            if ($return_values) {
                $results[] = [
                    'arguments' => $argument_list,
                    'result'    => $hypothesis(...$argument_list)
                ];
            } else {
                $hypothesis(...$argument_list);
            }
        }

        // Reset
        $this->generators = [];

        if ($return_values) {
            return $results;
        } else {
            return $this;
        }
    }

    /**
     * Convert the $callable to an infinite stream by calling it forever, yielding its value each time.
     *
     * @param callable $callable
     * @param          ...$args
     *
     * @return Generator
     */
    public static function stream(callable $callable, ...$args)
    {
        while(true) {
            yield $callable(...$args);
        }
    }

    // The following are generators which can be passed into given(), covering common use-cases.

    /**
     * Generate literally any possible value from any of the other generators in this class. Mostly useful for input
     * validation.
     *
     * @return Generator
     * @throws \Exception
     */
    public static function anything()
    {
        /** @var string[] $methods The methods in this class that we will pick from. */
        $methods = ['integers', 'accidents', 'garbage', 'evilStrings'];

        /** @var Generator[] $existing_generators They need to be stored after we first generate them, so we can keep
         * track of their state. */
        $existing_generators = [];

        while (true)
        {
            // Pick a random generator.
            $chosen_method = $methods[rand(0, count($methods) - 1)];

            // Init the generator and store it.
            if (!array_key_exists($chosen_method, $existing_generators))
            {
                $existing_generators[$chosen_method] = self::$chosen_method();
            }

            // Yield its value.
            yield $existing_generators[$chosen_method]->current();

            // Iterate the generator for next time.
            $existing_generators[$chosen_method]->next();
        }
    }

    /**
     * Generate random integers of any size. Start out with small integers, then double the available range on each
     * iteration. After the range has maxed out, start over.
     *
     * @return Generator
     */
    public static function integers()
    {
        // First, make sure the most common numbers are covered.
        yield 0;
        yield -1;
        yield 1;

        // Then generate random numbers from an increasing range.
        $half_of_randmax = mt_getrandmax() / 2;
        $lim = 1;
        $iterations = 0;
        while (true) {
            $iterations++;

            yield mt_rand(-$lim, $lim);

            if ($lim <= $half_of_randmax) {
                $lim *= 2;
            } else {
                // Start over when maxed out.
                $lim = 1;
            }
        }
    }

    /**
     * Generate random integers within the closed interval [$min, $max], i.e. including $min and $max.
     *
     * @param int $min Lower limit of integers to include.
     * @param int $max Upper limit of integers to include.
     *
     * @return Generator
     * @throws \Exception
     */
    public static function integerRange($min, $max)
    {
        $min = (int)$min;
        $max = (int)$max;

        if ($min > $max) {
            throw new \Exception("Invalid integer range: \$max should be at least $min, but it was set to $max");
        }

        while (true) {
            yield mt_rand($min, $max);
        }
    }

    /**
     * Generate integers in order, from $min to $max, with jumps of size $stride. Start over when finished.
     *
     * @param int $min
     * @param int $max
     * @param int $stride
     *
     * @return Generator
     * @throws \Exception
     */
    public static function everyInteger($min=0, $max=PHP_INT_MAX, $stride=1)
    {
        $min = (int)$min;
        $max = (int)$max;
        $stride = (int)$stride;

        if ($min > $max) {
            throw new \Exception("Invalid integer range: \$max should be at least $min, but it was set to $max");
        }

        if ($stride < 1) {
            throw new \Exception("Invalid integer range: \$stride should be at least 1, but it was set to $stride");
        }

        while (true) {
            for ($int = $min; $int < $max; $int++) {
                yield $int;
            }
        }
    }

    /**
     * Same as integers, just with floats. We use a much lower initial limit.
     *
     * @return Generator
     */
    public static function floats()
    {
        // First, make sure the most common numbers are covered.
        yield 0.0;
        yield -1.0;
        yield 1.0;

        // Then generate random numbers from an increasing range.
        $lim = 0.01;
        $iterations = 0;
        while (true) {
            $iterations++;

            yield $lim * (2 * mt_rand() / mt_getrandmax() - 1);

            if ($lim <= mt_getrandmax() / 2) {
                $lim *= 2;
            } else {
                // Start over when maxed out.
                $lim = 0.01;
            }
        }
    }

    /**
     * Same as integer range, just with floats.
     *
     * @param $min
     * @param $max
     *
     * @return Generator
     * @throws \Exception
     */
    public static function floatRange($min, $max)
    {
        $min = (float)$min;
        $max = (float)$max;

        if ($min > $max) {
            throw new \Exception("Invalid integer range: \$max should be at least $min, but it was set to $max");
        }

        while (true) {
            yield $min + mt_rand() / mt_getrandmax() * ($max - $min);
        }
    }

    /**
     * Generate random bools.
     *
     * @return Generator
     */
    public static function bools()
    {
        while (true) {
            yield !mt_rand(0,1);
        }
    }

    /**
     * Generate random ASCII characters.
     *
     * @param bool $extended Whether to use the extended ASCII codes.
     *
     * @return Generator
     */
    public static function chars($extended = false)
    {
        while (true) {
            yield chr(mt_rand(0, 128 * (1 + $extended) - 1));
        }
    }

    /**
     * Generate random numeric characters.
     *
     * @return Generator
     */
    public static function numericChars()
    {
        $numeric = '0123456789';
        while (true) {
            $index = mt_rand(0, 9);
            yield $numeric[$index];
        }
    }

    /**
     * Generate random alpanumeric characters. Letters are as likely as numbers.
     *
     * @return Generator
     */
    public static function alphanumerics()
    {
        $alphanumeric = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

        while (true) {
            $index = mt_rand(0, 61);
            yield $alphanumeric[$index];
        }
    }


    /**
     * Generate random hexadecimal characters.
     *
     * @param bool $lowercase Whether it should use a,b,c,d,e,f instead of the default A,B,C,D,E,F
     *
     * @return Generator
     */
    public static function hexChars($lowercase = false)
    {
        if ($lowercase) {
            $hex = '0123456789abcdef';
        } else {
            $hex = '0123456789ABCDEF';
        }
        while (true) {
            $index = mt_rand(0, 15);
            yield $hex[$index];
        }
    }

    /**
     * Generate random base64 characters.
     *
     * @param bool $url_variant Whether it should use the URL-safe variant instead of the default base64encode variant
     *
     * @return Generator
     */
    public static function base64Chars($url_variant)
    {
        $base64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

        if ($url_variant) {
            $base64[62] = '-';
            $base64[63] = '_';
        }

        while (true) {
            $index = mt_rand(0, 63);
            yield $base64[$index];
        }
    }

    /**
     * Generate random letters, lowercase or uppercase.
     *
     * @return Generator
     */
    public static function letters()
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

        while (true) {
            $index = mt_rand(0, 51);
            yield $letters[$index];
        }
    }

    /**
     * Generate random uppercase letters.
     *
     * @return Generator
     */
    public static function upperLetters()
    {
        $upper_letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        while (true) {
            $index = mt_rand(0, 25);
            yield $upper_letters[$index];
        }
    }

    /**
     * Generate random lowercase letters.
     *
     * @return Generator
     */
    public static function lowerLetters()
    {
        $lower_letters = 'abcdefghijklmnopqrstuvwxyz';

        while (true) {
            $index = mt_rand(0, 25);
            yield $lower_letters[$index];
        }
    }

    /**
     * Generate random characters from a custom charset.
     *
     * @param string $charset
     *
     * @return Generator
     */
    public static function charsFromCharset($charset)
    {
        $len = mb_strlen($charset);

        while (true) {
            $index = mt_rand(0, $len - 1);
            yield $charset[$index];
        }
    }

    /**
     * Generate random ASCII strings with a maximum length of $max_len.
     *
     * @param      $max_len
     * @param bool $extended Whether to use the extended ASCII codes.
     *
     * @return Generator
     */
    public static function asciiStrings($max_len, $extended = false)
    {
        $generator = self::chars($extended);
        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate random alphanumeric strings with a maximum length of $max_len.
     *
     * @param $max_len
     *
     * @return Generator
     */
    public static function alphanumericStrings($max_len)
    {
        $generator = self::alphanumerics();
        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate random numeric strings with a maximum length of $max_len.
     *
     * @param $max_len
     *
     * @return Generator
     */
    public static function numericStrings($max_len)
    {
        $generator = self::numericChars();
        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate random hex strings with a maximum length of $max_len.
     *
     * @param $max_len
     *
     * @return Generator
     */
    public static function hexStrings($max_len, $lowercase = false)
    {
        $generator = self::hexChars($lowercase);
        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate random hex strings with a maximum length of $max_len.
     *
     * @param int $max_len
     * @param bool $url_variant
     *
     * @return Generator
     */
    public static function base64Strings($max_len, $url_variant = false)
    {
        $generator = self::base64Chars($url_variant);
        while (true) {
            $base_string = self::stringsFromChars($generator, $max_len);
            if ($url_variant) {
                yield $base_string;
            } else {
                $padding = str_repeat("=", strlen($base_string) % 4);
                yield $base_string . $padding;
            }
        }
    }

    /**
     * Generate random letter strings with a maximum length of $max_len.
     *
     * @param $max_len
     *
     * @return Generator
     */
    public static function letterStrings($max_len)
    {
        $generator = self::letters();
        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate random uppercase letter strings with a maximum length of $max_len
     *
     * @param $max_len
     *
     * @return Generator
     */
    public static function upperStrings($max_len)
    {
        $generator = self::upperLetters($max_len);
        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate random lowercase letter strings with a maximum length of $max_len
     *
     * @param $max_len
     *
     * @return Generator
     */
    public static function lowerStrings($max_len)
    {
        $generator = self::lowerLetters($max_len);
        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate random characters from a custom charset.
     *
     * @param string $charset
     *
     * @return Generator
     */
    public static function stringsFromCharset($max_len, $charset)
    {
        $generator = self::charsFromCharset($charset);

        while (true) {
            yield self::stringsFromChars($generator, $max_len);
        }
    }

    /**
     * Generate values chosen from the input array.
     *
     * @param array $input
     *
     * @return Generator
     */
    public static function chooseFrom(array $input)
    {
        $input = array_values($input);
        while (true) {
            yield $input[mt_rand(0, count($input) - 1)];
        }
    }

    /**
     * Generate values chosen from the input array, in order.
     *
     * @param array $input
     *
     * @return Generator
     */
    public static function cycleThrough(array $input)
    {
        while (true) {
            foreach ($input as $element) {
                yield $element;
            }
        }
    }

    /**
     * Generate arrays where the elements are given by the input generator.
     *
     * @param Generator $input
     * @param           $max_array_size
     *
     * @return Generator
     */
    public static function arrays(Generator $input, $max_array_size)
    {
        while (true) {
            $ret = [];
            for ($i = 0, $max = mt_rand(0, $max_array_size); $i < $max; $i++) {
                $ret[] = $input->current();
                $input->next();
            }
            yield $ret;
        }
    }

    /**
     * Generate arrays where the elements are given by the input generator and the arrays always have a given length
     *
     * @param Generator $input
     * @param           $array_size
     *
     * @return Generator
     */
    public static function fixedLengthArrays(Generator $input, $array_size)
    {
        while (true) {
            $ret = [];
            for ($i = 0, $max = $array_size; $i < $max; $i++) {
                $ret[] = $input->current();
                $input->next();
            }
            yield $ret;
        }
    }

    /**
     * Generate arrays that fulfill the fiven input schema
     *
     * @param array $array Associative arrays where the values are generators that generate the values that the fields
     *                     should have
     *
     * @return Generator
     */
    public static function arraySchema(array $array)
    {
        while (true) {
            $new_array = $array;
            array_walk($new_array, function(Generator &$generator) {
                $value = $generator->current();
                $generator->next();
                $generator = $value;
            });
            yield $new_array;
        }
    }

    /**
     * Generates permutations of the array.
     *
     * @param array $array
     *
     * @return Generator
     */
    public static function arrayPermutations(array $array)
    {
        while (true) {
            shuffle($array);
            yield $array;
        }
    }

    /**
     * Generates some values that a function will typically return if something is wrong, like null or an empty array.
     * Good for handling common failure modes.
     *
     * @return Generator
     */
    public static function accidents()
    {
        while (true) {
            yield null;
            yield false;
            yield true;
            yield 0;
            yield "0";
            yield [];
            yield "";
            yield new \stdClass();
        }
    }

    /**
     * Generate some weird values of all types. Mostly useful for input validation.
     *
     * @return Generator
     */
    public static function garbage()
    {
        while (true) {
            yield 0.1;
            yield mt_rand();
            yield -mt_rand();
            yield 0x48;
            yield "asdf";
            yield "någet Μη-ascii κείμενο";
            yield '\\\\\n\0';
            yield [1,2,3];
            yield ["@@@££€€€µµµ", null, new \stdClass(), [[]],[], '?' => ['r' => true, 4]];
            yield function () {};
            yield new \DateTime();
            yield new \Exception();
        }
    }

    /**
     * Generate evil strings, like those used for MySQL injection, XSS, etc.
     *
     * @return Generator
     */
    public static function evilStrings()
    {
        while (true) {
            yield "* WHERE 1=1; --";
            yield "<script>alert('xss')</script>";
        }
    }

    /**
     * @param array|Generator ...$generators
     *
     * @return Generator
     */
    public static function combine(...$generators)
    {
        while (true)
        {
            $index = rand(0, count($generators) - 1);

            // Pick a random generator.
            yield $generators[$index]->current();

            // Iterate the generator for next time.
            $generators[$index]->next();
        }
    }

    /**
     * @param array|Generator ...$generators
     *
     * @return Generator
     */
    public static function weightedCombine(...$descriptors)
    {
        /** @var float[]|int[] $weights */
        $weights = array_column($descriptors, 'weight');

        /** @var Generator[] $generators */
        $generators = array_column($descriptors, 'generator');

        $integrated_weights = [];
        $running_sum = 0;
        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new \Exception("Weight can not be less than 0 (was $weight)");
            }

            $running_sum += $weight;
            $integrated_weights[] = $running_sum;
        }

        while (true)
        {
            $location = mt_rand(0, $running_sum);

            $index = 0;
            while ($integrated_weights[$index++] < $location);

            // Pick a random generator.
            yield $generators[$index]->current();

            // Iterate the generator for next time.
            $generators[$index]->next();
        }
    }


    /**
     * Streams in chunks from the input, reshuffles them, and outputs elements in a shuffled order.
     *
     * @param Generator $input
     * @param           $chunk_size
     */
    public static function reshuffle(Generator $input, $chunk_size)
    {
        while (true) {
            $chunk = [];
            for ($i = 0; $i < $chunk_size; $i++) {
                $chunk[] = $input->current();
                $input->next();
            }
            shuffle($chunk);
            foreach($chunk as $element) {
                yield $element;
            }
        }
    }

    // The following are internal help functions.

    /**
     * The generator returned by this function will yield an argument list every time it is called, until the maximum
     * number of tests is reached. It does so by advancing each of the generators once and yielding, each iteration.
     *
     * @return Generator providing an argument list
     */
    private function generateArgumentLists()
    {
        for ($i = 0; $i < $this->max_tests; $i++) {
            $arg_list = [];

            foreach ($this->generators as &$generator) {
                $arg_list[] = $generator->current();
                $generator->next();
            }

            yield $arg_list;
        }
    }

    /**
     * Take a generator that makes chars and return a generator that makes strings out of those chars.
     *
     * @param Generator $char_generator
     * @param           $max_len
     *
     * @return string
     */
    private static function stringsFromChars(Generator &$char_generator, $max_len)
    {
        $ret = [];
        for ($i = 0, $max = mt_rand(0, $max_len); $i < $max; $i++) {
            $ret[] = $char_generator->current();
            $char_generator->next();
        }
        return implode('', $ret);
    }
}
