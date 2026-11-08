<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Moderator = 'moderator';
    case Employer = 'employer';
    case Trainer = 'trainer';       // Main Tutor
    case Apprentice = 'apprentice';
    case Mentor = 'mentor';         // Learning & Progress Mentor

    public function label(): string
    {
        return match($this) {
            self::Admin => 'Administrator',
            self::Moderator => 'Quality Moderator',
            self::Employer => 'Employer / Workplace Mentor',
            self::Trainer => 'GTA Main Tutor',
            self::Apprentice => 'Apprentice',
            self::Mentor => 'Learning & Progress Mentor',
        };
    }
}

