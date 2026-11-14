<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReminderMail extends Model
{
    protected $table = 'reminder_mail_outbox';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['snapshot'=>'array','recipient_id'=>'integer','version'=>'integer','prepared_by'=>'integer','approved_by'=>'integer','approver_auth_version'=>'integer','approved_at'=>'datetime','attempted_at'=>'datetime','finished_at'=>'datetime'];
    }
}
