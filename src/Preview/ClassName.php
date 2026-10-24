<?php

namespace Atoria\Alfred\Preview;

/** The fully-qualified name of the first class/interface/trait/enum declared in PHP source. */
class ClassName
{
    public static function fromCode(string $code): ?string
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (! is_array($t)) {
                continue;
            }

            if ($t[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $n = $tokens[$j];
                    if ($n === ';' || $n === '{') {
                        break;
                    }
                    if (is_array($n) && in_array($n[0], [T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                        $namespace .= $n[1];
                    }
                }
            }

            if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                // skip "::class" and anonymous classes
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && (($tokens[$prev] === '(') || (is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)))) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        return ltrim($namespace.'\\'.$tokens[$j][1], '\\');
                    }
                    if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        return null;
    }
}
