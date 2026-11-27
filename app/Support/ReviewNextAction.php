<?php

namespace App\Support;

use App\Models\ElectronicReviewPacket;
use App\Models\ProgressReviewDraft;
use App\Models\User;

final class ReviewNextAction
{
    public static function forSigning(User $user, ProgressReviewDraft $review, bool $paper, bool $checked): array
    {
        $packet = ElectronicReviewPacket::where('active_review_id', $review->id)
            ->where('apprentice_id', $review->apprentice_id)->first();

        if ($checked) {
            return ['label' => 'View checked review', 'url' => route('reviews.signed.form', $review)];
        }

        if ($packet) {
            return [
                'label' => match ($packet->state) {
                    'Draft' => 'Check and release for signatures',
                    'Released' => 'View outstanding signatures',
                    'All signed' => LearningAccess::manager($user) && $packet->prepared_by !== $user->id
                        ? 'Open independent check' : 'View review awaiting independent check',
                    'Declined' => 'Review declined signature',
                    default => 'Open participant signing record',
                },
                'url' => route('electronic-reviews.show', $packet),
            ];
        }

        return $paper
            ? ['label' => 'Open signed-copy check', 'url' => route('reviews.signed.form', $review)]
            : ['label' => 'Prepare for participant signatures', 'url' => route('electronic-reviews.prepare-form', $review)];
    }
}
