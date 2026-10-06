<?php

namespace App\Enums;

/**
 * The kinds of unit in the role tree.
 */
enum TreeUnitKind: string
{
    /** The dean's own unit, the top of one tree. */
    case Root = 'root';

    /** A Neptun course. */
    case Course = 'course';

    /** A part of a course, e.g. "SQL" in "Database". Subtopics can contain subtopics. */
    case Subtopic = 'subtopic';
}
