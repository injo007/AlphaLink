<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

class DmsBulkInstallIspPlanner
{
    /**
     * Recreates IspAction::build_vmtas(): lowercase every plucked name and
     * retain the first occurrence without filtering inactive ISP rows.
     *
     * @param  list<string>  $ispNames
     * @return list<string>
     */
    public function buildVmtaNames(array $ispNames): array
    {
        $uniqueNames = [];
        $seenNames = [];

        foreach ($ispNames as $ispName) {
            if (! is_string($ispName)) {
                throw new InvalidArgumentException('DMS VMTA ISP names must be strings.');
            }

            $name = strtolower($ispName);

            if (array_key_exists($name, $seenNames)) {
                continue;
            }

            $seenNames[$name] = true;
            $uniqueNames[] = $name;
        }

        return $uniqueNames;
    }
}
