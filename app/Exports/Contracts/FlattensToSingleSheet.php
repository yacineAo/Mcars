<?php

declare(strict_types=1);

namespace App\Exports\Contracts;

/**
 * A multi-sheet export that can name the one sheet a flat format should carry.
 *
 * CSV has no notion of sheets, so Laravel Excel writes only the first one. For the
 * fleet report that is the four-line summary, silently dropping the per-car rows —
 * which are the reason anyone opens it as a CSV. An export that spans sheets says
 * here which one survives flattening.
 */
interface FlattensToSingleSheet
{
    /**
     * The sheet to export when the target format holds only one.
     */
    public function flatSheet(): object;
}
