<?php
namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;

class ApplicationSchema
{
    // Presence-only contract from the unchanged reference migrations through Pack19.
    // Extra target tables/columns are allowed. Types, indexes, FKs and data are not certified.
    public const TABLES = [
        'account_access_events'=>['actor_id','created_at','event','id','origin','user_id'],
        'account_onboarding_tokens'=>['auth_version','created_at','expires_at','issuer_auth_version','issuer_id','purpose','token_hash','user_id'],
        'account_second_factors'=>['confirmed_at','created_at','last_step','recovery_hashes','secret','setup_binding','setup_expires_at','updated_at','user_id'],
        'apprentice_markers'=>['apprentice_id','code','created_at','id','recorded_by','updated_at'],
        'apprentices'=>['alert_channel','alert_history','alert_mobile','alert_mobile_verified_at','alert_revision','alerts_enabled','careers_adviser_id','created_at','email','employer','employer_id','english_tutor_id','first_name','group_name','id','last_name','maths_tutor_id','mentor_id','planned_end_date','programme','programme_id','reference','start_date','tutor_group_id','tutor_id','updated_at','user_id','version'],
        'assignment_change_events'=>['actor_id','after_links','apprentice_id','before_links','created_at','id'],
        'attendance_marks'=>['adjustment','apprentice_id','arrival','code','comment','created_at','departure','id','otj_confirmed','otj_minutes','parts','recorded_by','training_session_id','updated_at'],
        'audit_events'=>['apprentice_id','created_at','event','id','user_id'],
        'cache'=>['expiration','key','value'],
        'cache_locks'=>['expiration','key','owner'],
        'career_discussions'=>['actions','advice','adviser_id','apprentice_id','created_at','discussion_date','followups','goals','history','id','next_step','options','outcome','recorded_by','referral','type','updated_at','version'],
        'careers_prompt_events'=>['actor_id','careers_prompt_id','created_at','event','id','origin'],
        'careers_prompts'=>['apprentice_id','block','created_at','id','plan_hash','recipient_id','seen_at','source_key','start_date','updated_at'],
        'case_studies'=>['approved_copies','author_id','created_at','fields','history','id','privacy','status','updated_at','version'],
        'data_import_batches'=>['applied_at','applied_by','created_at','id','kind','prepared_by','preview_hash','rows','source_hash','source_name','source_reference','state','updated_at','version'],
        'data_import_events'=>['actor_id','created_at','data_import_batch_id','event','id'],
        'directory_events'=>['created_at','event','id','kind','record_id','user_id'],
        'employers'=>['active','contact_email','contact_name','contact_phone','created_at','id','name','record_version','reference','size_band','updated_at'],
        'evidence_copy_events'=>['created_at','event','id','origin','plan_id','summary'],
        'evidence_copy_plans'=>['created_at','finished_at','id','manifest','manifest_hash','reference','result','source_disk','started_at','state','target_disk','updated_at','version'],
        'evidence_restore_events'=>['created_at','event','id','run_id','summary'],
        'evidence_restore_runs'=>['copy_plan_id','created_at','finished_at','id','manifest','manifest_hash','reference','result','started_at','state','updated_at','version'],
        'executive_access_events'=>['action','created_at','executive_access_session_id','id','record_reference'],
        'executive_access_sessions'=>['created_at','ended_at','id','reason','reference','started_at','updated_at','user_id'],
        'extra_trainings'=>['agreement','apprentice_id','approval','compensation','created_at','end_time','evidence','excluded_minutes','file','history','id','learning','minutes','parts','provider','recorded_by','start_time','topic','training_date','tutor_group_id','updated_at','version','withdrawal_reason','withdrawn_at','working'],
        'failed_jobs'=>['connection','exception','failed_at','id','payload','queue','uuid'],
        'historical_otj_records'=>['apprentice_id','created_at','data_import_batch_id','end_time','evidence_reference','excluded_minutes','fingerprint','history','id','minutes','programme_identity','start_time','topic','training_date','updated_at','version','withdrawn_at'],
        'individual_delivery_plans'=>['active','apprentice_id','approved','created_at','draft','history','id','updated_at','version'],
        'job_batches'=>['cancelled_at','created_at','failed_job_ids','failed_jobs','finished_at','id','name','options','pending_jobs','total_jobs'],
        'jobs'=>['attempts','available_at','created_at','id','payload','queue','reserved_at'],
        'learning_block_controls'=>['apprentice_id','created_at','enabled','exceptions','history','id','updated_at','version'],
        'learning_events'=>['apprentice_id','created_at','event','id','kind','record_id','user_id'],
        'learning_tasks'=>['answers','apprentice_id','block','created_at','deadline_history','due_date','id','reference','status','submissions','submitted_at','template','theme','title','updated_at','version'],
        'management_packs'=>['captured_by','created_at','id','snapshot','snapshot_hash','title'],
        'marker_change_events'=>['actor_id','after_codes','apprentice_id','before_codes','created_at','id','reason','source'],
        'marking_reminder_events'=>['created_at','event','id','marking_reminder_id','origin','user_id'],
        'marking_reminders'=>['created_at','id','learning_task_id','recipient_id','seen_at','source_key','submission_version','submitted_at','updated_at'],
        'migrations'=>['batch','id','migration'],
        'monthly_scorecards'=>['apprentice_id','comment','created_at','history','id','month','scores','training_session_id','tutor_id','updated_at','version'],
        'password_reset_tokens'=>['created_at','email','token'],
        'portal_bootstrap_states'=>['completed_at','completed_by','id'],
        'portal_reminder_runs'=>['counts','created_at','id'],
        'programme_outcomes'=>['apprentice_id','context','created_at','history','id','updated_at','version'],
        'programmes'=>['active','created_at','id','level','name','record_version','standard_code','updated_at','version'],
        'progress_review_drafts'=>['apprentice_id','author_id','created_at','fields','history','id','source_snapshot','state','updated_at','version'],
        'qualifications'=>['achievement_date','apprentice_id','created_at','files','history','id','level','notes','result','start_date','status','subject','title','type','updated_at','version'],
        'reminder_mail_events'=>['actor_id','created_at','event','id','outbox_id'],
        'reminder_mail_outbox'=>['approved_at','approved_by','approver_auth_version','attempted_at','created_at','fingerprint','finished_at','id','prepared_by','recipient_id','snapshot','state','updated_at','version'],
        'review_reminder_events'=>['actor_id','created_at','event','id','origin','review_reminder_id'],
        'review_reminders'=>['apprentice_id','created_at','id','recipient_id','seen_at','signed_review_record_id','source','source_key','updated_at'],
        'sessions'=>['id','ip_address','last_activity','payload','user_agent','user_id'],
        'signed_review_events'=>['actor_id','created_at','event','id','signed_review_record_id'],
        'signed_review_records'=>['apprentice_id','checked_at','checked_by','created_at','file','history','id','meeting_date','prepared_by','progress_review_draft_id','review_version','snapshot','snapshot_hash','updated_at','version','withdrawn_at'],
        'smart_actions'=>['apprentice_id','completion_date','created_at','created_by','due_date','evidence_method','evidence_note','feedback','history','id','learning_reference','learning_task_id','measure','method','owner','relevance','smart_data','status','support','title','updated_at','version'],
        'source_reconciliation_events'=>['actor_id','created_at','event','id','note','reconciliation_id'],
        'source_reconciliations'=>['apprentice_id','author_id','binding','created_at','decision','id','reason','review_note','reviewed_at','reviewer_id','scope_note','snapshot','source_reference','state','updated_at','version'],
        'staff_development_events'=>['actor_id','created_at','event','id','reference','user_id'],
        'staff_development_profiles'=>['created_at','id','manager_id','payload','updated_at','user_id','version'],
        'staff_duties'=>['careers_adviser','created_at','english_tutor','history','maths_tutor','mentor','updated_at','user_id','version'],
        'support_capabilities'=>['apprentice_ids','created_at','duty','group_ids','id','scope','updated_at','user_id','version'],
        'support_concerns'=>['acknowledged_at','acknowledged_by','action_due','apprentice_id','closed_at','code','created_at','followups','history','id','initial_note','next_action','next_review','other_code','outcome','owner_id','raised_by','status','updated_at','version'],
        'support_permission_events'=>['actor_id','after_state','before_state','created_at','id','reason','user_id'],
        'support_plan_reminder_events'=>['actor_id','created_at','event','id','reminder_id'],
        'support_plan_reminders'=>['created_at','dismissed_at','id','recipient_id','seen_at','source','source_key','support_plan_id','updated_at'],
        'support_plans'=>['active_plan','activities','apprentice_id','closure_outcome','concern_ids','created_at','draft','files','history','id','review_owner_id','review_owner_revision','reviews','status','updated_at','version'],
        'survey_receipts'=>['apprentice_id','checks','created_at','history','id','kind','receipt_revision','recorded_by','submitted_date','updated_at','version'],
        'task_evidence'=>['bytes','created_at','disk','id','kind','learning_task_id','mime','name','path','sha256','updated_at','uploaded_by'],
        'teaching_instructions'=>['acknowledgements','apprentice_id','created_at','draft_revision','draft_text','history','id','private_note','recipient_ids','release','updated_at','version'],
        'training_plan_workspaces'=>['apprentice_id','created_at','history','id','proposal','reviews','rpl','source_hash','updated_at','version'],
        'training_sessions'=>['breaks','cancellation_reason','created_at','created_by','delivered_learning','end_time','history','id','members','name','planned_learning','session_date','start_time','state','tutor_group_id','updated_at','version'],
        'training_topics'=>['apprentice_id','area','completion_date','created_at','draft','evidence_stage','files','history','id','level','planned_hours','status','subject','submissions','topic','updated_at','version'],
        'tutor_groups'=>['active','created_at','id','name','programme_id','record_version','reference','tutor_id','updated_at'],
        'users'=>['active','auth_version','created_at','email','email_verified_at','id','name','password','record_version','remember_token','role','subject_scope','updated_at'],
    ];

    public static function inspect(Connection $connection): array
    {
        try {
            if (!in_array($connection->getDriverName(),['mysql','mariadb'],true)) {
                return self::unavailable('actual_driver_not_supported');
            }
            return self::inspectSchema($connection->getSchemaBuilder());
        } catch (\Throwable) {
            return self::unavailable('schema_probe_failed');
        }
    }

    public static function unavailable(string $reason='not_requested'): array
    {
        return ['status'=>$reason==='not_requested'?'not_checked':'blocked','reason'=>$reason,'required_tables'=>count(self::TABLES),
            'required_columns'=>array_sum(array_map('count',self::TABLES)),'tables_checked'=>0,'missing_tables'=>[],'missing_columns'=>[],
            'writes_performed'=>false,'presence_only'=>true];
    }

    /** Database-independent metadata checks; the hosted entry point additionally enforces the native driver. */
    public static function inspectSchema(Builder $schema): array
    {
        $result=self::unavailable();$result['status']='pass';$result['reason']='required_names_present';
        try {
            foreach(self::TABLES as $table=>$columns) {
                $result['tables_checked']++;
                if (!$schema->hasTable($table)) { $result['missing_tables'][]=$table;continue; }
                $missing=array_values(array_diff($columns,$schema->getColumnListing($table)));
                if ($missing) { $result['missing_columns'][$table]=$missing; }
            }
        } catch (\Throwable) { return self::unavailable('schema_probe_failed'); }
        if ($result['missing_tables']||$result['missing_columns']) { $result['status']='blocked';$result['reason']='required_names_missing'; }
        return $result;
    }
}
