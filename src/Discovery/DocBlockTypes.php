<?php

namespace Square1\Mpp\Discovery;

use ReflectionClass;
use ReflectionProperty;

/**
 * Reads the type that a docblock states, and resolves the class names in it.
 *
 * A PHP `array` type does not name its member type. `list<Scoreline>` in a
 * docblock does, and static analysers already read it. This class gives
 * {@see ObjectSchema} the same text, so that an array property can publish its
 * `items`.
 *
 * A docblock writes a class name as the code around it writes one: short, and
 * resolved through the `use` statements of the file. This class therefore reads
 * those statements, and it applies the same rules as PHP: a leading backslash
 * is a full name, a first segment that matches an import expands, and anything
 * else belongs to the namespace of the class.
 */
final class DocBlockTypes
{
    /**
     * The imports of each file that the class has read, keyed by path.
     *
     * A data object commonly holds several arrays, and a document commonly
     * holds one class several times. Each file is therefore tokenised once.
     *
     * @var array<string, array<string, string>>
     */
    private static array $imports = [];

    /**
     * Returns the type that the docblock of a property states, or null.
     *
     * A promoted property carries its documentation on the `@param` tag of the
     * constructor, and any other property carries it on its own `@var` tag.
     */
    public static function forProperty(ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();

        if (is_string($doc) && ($type = self::afterTag($doc, '@var')) !== null) {
            return $type;
        }

        if (! $property->isPromoted()) {
            return null;
        }

        $doc = $property->getDeclaringClass()->getConstructor()?->getDocComment();

        return is_string($doc)
            ? self::afterTag($doc, '@param', $property->getName())
            : null;
    }

    /**
     * Resolves a class name as a docblock writes it.
     *
     * @return class-string|null null when no such class exists
     */
    public static function className(string $written, ReflectionClass $context): ?string
    {
        $written = trim($written);

        if ($written === '') {
            return null;
        }

        if (str_starts_with($written, '\\')) {
            return self::existing(ltrim($written, '\\'));
        }

        $head = explode('\\', $written)[0];
        $imports = self::imports($context);

        if (isset($imports[strtolower($head)])) {
            return self::existing($imports[strtolower($head)].substr($written, strlen($head)));
        }

        $namespace = $context->getNamespaceName();

        if ($namespace !== '' && ($resolved = self::existing($namespace.'\\'.$written)) !== null) {
            return $resolved;
        }

        return self::existing($written);
    }

    /**
     * Returns the type expression that follows a tag.
     *
     * `$variable` names the variable of a `@param` tag. The method reads the
     * expression up to the variable, because a generic type carries spaces of
     * its own, as in `array<string, Scoreline>`.
     */
    private static function afterTag(string $doc, string $tag, ?string $variable = null): ?string
    {
        $pattern = $variable === null
            ? '/'.preg_quote($tag, '/').'\s+(.+)$/m'
            : '/'.preg_quote($tag, '/').'\s+(.+?)\s+\$'.preg_quote($variable, '/').'\b/';

        if (preg_match($pattern, $doc, $match) !== 1) {
            return null;
        }

        return $variable === null ? self::firstType($match[1]) : trim($match[1]);
    }

    /**
     * Returns the type at the start of a line, and drops the description that
     * can follow it.
     *
     * The method counts the angle brackets, so that it does not stop inside
     * `array<string, Scoreline>`.
     */
    private static function firstType(string $line): ?string
    {
        $depth = 0;
        $type = '';

        foreach (str_split(trim($line)) as $character) {
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            } elseif ($depth === 0 && trim($character) === '') {
                break;
            }

            $type .= $character;
        }

        return $type === '' ? null : $type;
    }

    /**
     * Returns the `use` statements of the file of a class, keyed by the alias
     * in lower case.
     *
     * @return array<string, string>
     */
    private static function imports(ReflectionClass $class): array
    {
        $file = $class->getFileName();

        if ($file === false || ! is_readable($file)) {
            return [];
        }

        if (isset(self::$imports[$file])) {
            return self::$imports[$file];
        }

        $tokens = token_get_all((string) file_get_contents($file));
        $imports = [];
        $index = 0;
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            // The imports of a file stand above its first type declaration.
            // Below it, `use` imports a trait or binds a closure variable, and
            // neither one names a class that a docblock can write.
            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                break;
            }

            if (is_array($token) && $token[0] === T_USE) {
                $index = self::readImport($tokens, $index + 1, $imports);

                continue;
            }

            $index++;
        }

        return self::$imports[$file] = $imports;
    }

    /**
     * Reads one `use` statement, and returns the index after it.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function readImport(array $tokens, int $index, array &$imports): int
    {
        $count = count($tokens);
        $statement = '';

        while ($index < $count) {
            $token = $tokens[$index++];

            if ($token === ';') {
                break;
            }

            $statement .= is_array($token) ? $token[1] : $token;
        }

        $statement = trim($statement);

        // `use function` and `use const` import neither a class nor an
        // interface, so a docblock type never resolves through them.
        if (preg_match('/^(function|const)\b/i', $statement) === 1) {
            return $index;
        }

        if (preg_match('/^(.*?)\{(.*)\}$/s', $statement, $group) === 1) {
            foreach (explode(',', $group[2]) as $entry) {
                self::recordImport(trim($group[1]).trim($entry), $imports);
            }

            return $index;
        }

        self::recordImport($statement, $imports);

        return $index;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private static function recordImport(string $entry, array &$imports): void
    {
        $parts = preg_split('/\s+as\s+/i', trim($entry));

        if ($parts === false || $parts === []) {
            return;
        }

        $name = ltrim(trim($parts[0]), '\\');

        if ($name === '') {
            return;
        }

        $alias = isset($parts[1]) ? trim($parts[1]) : self::lastSegment($name);

        $imports[strtolower($alias)] = $name;
    }

    private static function lastSegment(string $name): string
    {
        $segments = explode('\\', $name);

        return (string) end($segments);
    }

    /**
     * @return class-string|null
     */
    private static function existing(string $name): ?string
    {
        return class_exists($name) || interface_exists($name) || enum_exists($name) ? $name : null;
    }
}
