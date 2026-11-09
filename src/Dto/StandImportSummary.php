<?php

namespace Nyartcc\GroundCoordination\Dto;

/** What one stand import did. */
final class StandImportSummary
{
    public int $added = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $skippedWithoutRef = 0;

    /** Features whose name occurs more than once in the file. */
    public int $skippedAmbiguous = 0;
}
