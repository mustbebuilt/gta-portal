<?php
namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;

class ApplicationSchema
{
    // Presence-only contract from the unchanged reference migrations through Pack38.
    // Extra target tables/columns are allowed. Types, indexes, FKs and data are not certified.
    public const TABLES = [
        'timetable_planner_access'=>['active','created_at','granted_by','history','updated_at','user_id','version'],
        'timetable_rooms'=>['active','capacity','created_at','created_by','history','id','name','site','updated_at','uses','version'],
        'timetable_staff_windows'=>['created_at','end_time','id','proof','start_time','updated_at','updated_by','user_id','version','work_date','working'],
        'timetable_plans'=>['check_proof','created_at','created_by','id','publication','published_at','request_token','source','source_hash','state','updated_at','version','withdrawal'],
        'timetable_plan_entries'=>['end_time','group_id','headcount','id','kind','link_proof','plan_id','room_id','site','source_hash','staff_id','start_time','title','training_session_id','work_date'],
        'timetable_planning_events'=>['actor_id','created_at','event','id','payload','plan_id','record_version'],

        'review_appointments'=>['active_slot','apprentice_id','closure','created_at','created_by','ends_at','followup','followup_version','id','outcome','publication','published_at','request_token','source','source_hash','starts_at','state','updated_at','version'],
        'review_appointment_parties'=>['appointment_id','appointment_version','created_at','id','identity_hash','proof','role','user_id'],
        'review_appointment_responses'=>['appointment_id','appointment_version','choice','created_at','id','proof','responded_at','response_version','source_hash','updated_at','user_id'],
        'review_appointment_events'=>['actor_id','appointment_id','created_at','event','id','payload','record_version'],

        'account_access_events'=>['actor_id','created_at','event','id','origin','user_id'],
        'account_onboarding_tokens'=>['auth_version','created_at','expires_at','issuer_auth_version','issuer_id','purpose','token_hash','user_id'],
        'account_second_factors'=>['confirmed_at','created_at','last_step','recovery_hashes','secret','setup_binding','setup_expires_at','updated_at','user_id'],
        'alert_followup_cases'=>['checked_by','closed_at','created_at','delivery_id','due_on','id','owner_id','proposal','resolution','state','updated_at','version'],
        'alert_receipt_batches'=>['checked_at','checked_by','created_at','id','prepared_by','request_token','review_note','rows','rows_hash','source_reference','state','updated_at','version','withdrawn_at'],
        'alert_receipt_events'=>['actor_id','batch_id','case_id','created_at','delivery_id','event','id','private_note','record_version'],
        'alert_receipt_records'=>['attempt_hash','batch_id','created_at','delivery_id','evidence_kind','fingerprint','id','occurred_at','outcome','slot_key','source_reference','withdrawn_at'],
        'apprentice_alert_controls'=>['created_at','history','id','paused','updated_at','updated_by','version'],
        'apprentice_alert_deliveries'=>['apprentice_id','auth_version','channel','completed_at','config_hash','created_at','cycle_date','history','id','preference_revision','provider_id','reason','recipient_hash','recipient_id','reconciliation','reserved_at','seen_at','source','source_hash','state','updated_at'],
        'apprentice_experience_entries'=>['apprentice_id','created_at','detail','happened_on','history','id','kind','request_token','requester_id','response','reviewer_id','state','title','updated_at','version'],
        'apprentice_invitation_bindings'=>['apprentice_id','created_at','identity_hash','user_id'],
        'apprentice_markers'=>['apprentice_id','code','created_at','id','recorded_by','updated_at'],
        'apprentice_mobile_challenges'=>['apprentice_id','attempts','auth_version','code_hash','config_hash','created_at','expires_at','id','phone_hash','preference_revision','provider_id','session_hash','state','updated_at','user_id'],
        'apprentice_onboarding_events'=>['actor_id','apprentice_id','created_at','event','id','identity_reference','user_id'],
        'apprentice_start_check_events'=>['actor_id','check_id','check_version','confirmed','created_at','id'],
        'apprentice_start_checks'=>['apprentice_id','confirmed_at','created_at','id','item','updated_at','user_id','version'],
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
        'destination_contacts'=>['apprentice_id','contacted_on','created_at','detail','id','method','next_followup_on','recorded_by','request_token','result','source','source_hash','updated_at','version','void_reason','voided_at','voided_by'],
        'destination_events'=>['actor_id','apprentice_id','contact_id','created_at','event','id','proposal_id','record_version'],
        'destination_proposals'=>['apprentice_id','checked_at','checked_by','created_at','id','outcome_id','payload','payload_hash','prepared_by','receipt','request_token','review_note','source','source_hash','state','updated_at','version','withdrawn_at'],
        'directory_events'=>['created_at','event','id','kind','record_id','user_id'],
        'electronic_review_events'=>['actor_id','authority_id','created_at','event','id','packet_id','private_note','record_version'],
        'electronic_review_packets'=>['active_review_id','apprentice_id','checked_at','checked_by','completion','completion_hash','created_at','expires_on','id','prepared_by','progress_review_draft_id','released_at','released_by','signed_review_record_id','snapshot','snapshot_hash','state','updated_at','version','withdrawn_at','withdrawn_by'],
        'electronic_review_signers'=>['attestation','attestation_hash','attested_at','auth_version','created_at','expected_name','id','identity_hash','packet_id','role','state','updated_at','user_id','version'],
        'employer_apprentice_grants'=>['active','apprentice_id','created_at','employer_id','employer_version','history','id','identity_hash','identity_reference','updated_at','user_id','verified_by','version'],
        'employer_portal_actions'=>['apprentice_id','created_at','created_by','detail','due_date','employer_apprentice_grant_id','history','id','kind','responded_at','responded_by','response','response_note','source','source_hash','source_id','state','title','updated_at','version'],
        'employer_start_checks'=>['confirmed_at','created_at','id','item','scope_hash','updated_at','user_id','version'],
        'employer_start_events'=>['actor_id','check_id','created_at','event','id','payload'],
        'employers'=>['active','contact_email','contact_name','contact_phone','created_at','id','name','record_version','reference','size_band','updated_at'],
        'evidence_copy_events'=>['created_at','event','id','origin','plan_id','summary'],
        'evidence_copy_plans'=>['created_at','finished_at','id','manifest','manifest_hash','reference','result','source_disk','started_at','state','target_disk','updated_at','version'],
        'evidence_restore_events'=>['created_at','event','id','run_id','summary'],
        'evidence_restore_runs'=>['copy_plan_id','created_at','finished_at','id','manifest','manifest_hash','reference','result','started_at','state','updated_at','version'],
        'executive_access_events'=>['action','created_at','executive_access_session_id','id','record_reference'],
        'executive_access_sessions'=>['created_at','ended_at','id','reason','reference','started_at','updated_at','user_id'],
        'extra_trainings'=>['agreement','apprentice_id','approval','compensation','created_at','end_time','evidence','excluded_minutes','file','history','id','learning','minutes','parts','provider','recorded_by','start_time','topic','training_date','tutor_group_id','updated_at','version','withdrawal_reason','withdrawn_at','working'],
        'failed_jobs'=>['connection','exception','failed_at','id','payload','queue','uuid'],
        'gateway_readiness_checks'=>['binding_hash','checked_by','checked_on','created_at','criterion_key','evidence_reference','gateway_readiness_plan_id','history','id','note','state','updated_at','version'],
        'gateway_readiness_plans'=>['apprentice_id','approved_at','approved_by','basis','cohort','created_at','created_by','criteria','history','id','source_hash','source_reference','state','updated_at','version'],
        'help_narration_events'=>['actor_id','created_at','event','id','private_note','record_id','record_version'],
        'help_narration_records'=>['binding','created_at','file','guide_hash','guide_id','history','id','publication','publication_hash','published_guide_key','reviewed_at','reviewed_by','source_reference','state','transcript','updated_at','uploaded_by','version'],
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
        'portal_questionnaire_assignments'=>['apprentice_id','assigned_by','created_at','cycle_code','draft_answers','due_date','form_id','id','recipient_id','state','submission_count','submitted_at','updated_at','version'],
        'portal_questionnaire_events'=>['actor_id','assignment_id','created_at','event','form_id','id','private_reason','record_version'],
        'portal_questionnaire_forms'=>['approved_by','authored_by','content_hash','created_at','id','introduction','kind','privacy_notice','published_at','questions','revision','series_id','state','title','updated_at','version'],
        'portal_questionnaire_submissions'=>['answer_payload','assignment_id','checked_at','checked_by','concern_id','created_at','followup','followup_history','followup_resolved_at','followup_resolved_by','id','payload_hash','private_note','revision','shared_note','state','submitted_at','updated_at','version'],
        'portal_reminder_runs'=>['counts','created_at','id'],
        'programme_outcomes'=>['apprentice_id','context','created_at','history','id','updated_at','version'],
        'programmes'=>['active','created_at','id','level','name','record_version','standard_code','updated_at','version'],
        'progress_review_drafts'=>['apprentice_id','author_id','created_at','fields','history','id','source_snapshot','state','updated_at','version'],
        'qualifications'=>['achievement_date','apprentice_id','created_at','files','history','id','level','notes','result','start_date','status','subject','title','type','updated_at','version'],
        'reminder_mail_events'=>['actor_id','created_at','event','id','outbox_id'],
        'reminder_mail_outbox'=>['approved_at','approved_by','approver_auth_version','attempted_at','created_at','fingerprint','finished_at','id','prepared_by','recipient_id','snapshot','state','updated_at','version'],
        'review_preparation_briefs'=>['applied','applied_at','applied_by','applied_review_version','apprentice_id','created_at','id','period_from','period_to','prepared_by','progress_review_draft_id','source','source_hash','suggested','updated_at'],
        'review_reminder_events'=>['actor_id','created_at','event','id','origin','review_reminder_id'],
        'review_reminders'=>['apprentice_id','created_at','id','recipient_id','seen_at','signed_review_record_id','source','source_key','updated_at'],
        'review_signing_authorities'=>['active','apprentice_id','authority_reference','created_at','employer_apprentice_grant_id','expires_on','grant_binding','history','id','updated_at','verified_by','version'],
        'sessions'=>['id','ip_address','last_activity','payload','user_agent','user_id'],
        'signed_review_events'=>['actor_id','created_at','event','id','signed_review_record_id'],
        'signed_review_records'=>['apprentice_id','checked_at','checked_by','created_at','file','history','id','meeting_date','prepared_by','progress_review_draft_id','review_version','snapshot','snapshot_hash','updated_at','version','withdrawn_at'],
        'smart_actions'=>['apprentice_id','completion_date','created_at','created_by','due_date','evidence_method','evidence_note','feedback','history','id','learning_reference','learning_task_id','measure','method','owner','relevance','smart_data','status','support','title','updated_at','version'],
        'source_reconciliation_events'=>['actor_id','created_at','event','id','note','reconciliation_id'],
        'source_reconciliations'=>['apprentice_id','author_id','binding','created_at','decision','id','reason','review_note','reviewed_at','reviewer_id','scope_note','snapshot','source_reference','state','updated_at','version'],
        'staff_development_events'=>['actor_id','created_at','event','id','reference','user_id'],
        'staff_development_profiles'=>['created_at','id','manager_id','payload','updated_at','user_id','version'],
        'staff_duties'=>['careers_adviser','created_at','english_tutor','history','maths_tutor','mentor','updated_at','user_id','version'],
        'staff_handover_acknowledgements'=>['acknowledged_at','batch_id','created_at','id','proof','scope_hash','updated_at','user_id','version'],
        'staff_handover_batches'=>['created_at','created_by','expires_at','from_user_id','id','receipt','review_note','reviewed_at','reviewed_by','slot','source','source_hash','state','target_user_id','updated_at','version'],
        'staff_handover_events'=>['actor_id','batch_id','created_at','event','id','payload','record_version'],
        'staging_acceptance_checks'=>['created_at','details','details_hash','id','outcome','recorded_by','review_note','review_proof','review_state','reviewed_at','reviewed_by','run_id','scenario_id','tested_at','updated_at','version'],
        'staging_acceptance_events'=>['actor_id','created_at','event','id','payload','record_version','run_id'],
        'staging_acceptance_issues'=>['created_at','details','details_hash','due_on','id','owner_id','parent_id','proposal','recorded_by','review_proof','reviewed_at','reviewed_by','run_id','scenario_id','severity','state','updated_at','version'],
        'staging_acceptance_runs'=>['accepted_at','accepted_by','created_at','created_by','details','details_hash','id','parent_id','receipt','source_hash','source_snapshot','state','updated_at','version'],
        'support_capabilities'=>['apprentice_ids','created_at','duty','group_ids','id','scope','updated_at','user_id','version'],
        'support_concerns'=>['acknowledged_at','acknowledged_by','action_due','apprentice_id','closed_at','code','created_at','followups','history','id','initial_note','next_action','next_review','other_code','outcome','owner_id','raised_by','status','updated_at','version'],
        'support_permission_events'=>['actor_id','after_state','before_state','created_at','id','reason','user_id'],
        'support_plan_reminder_events'=>['actor_id','created_at','event','id','reminder_id'],
        'support_plan_reminders'=>['created_at','dismissed_at','id','recipient_id','seen_at','source','source_key','support_plan_id','updated_at'],
        'support_plans'=>['active_plan','activities','apprentice_id','closure_outcome','concern_ids','created_at','draft','files','history','id','review_owner_id','review_owner_revision','reviews','status','updated_at','version'],
        'survey_receipts'=>['apprentice_id','checks','created_at','history','id','kind','receipt_revision','recorded_by','submitted_date','updated_at','version'],
        'task_evidence'=>['bytes','created_at','description','disk','id','kind','learning_task_id','mime','name','path','sha256','updated_at','uploaded_by'],
        'teaching_instructions'=>['acknowledgements','apprentice_id','created_at','draft_revision','draft_text','history','id','private_note','recipient_ids','release','updated_at','version'],
        'training_plan_workspaces'=>['apprentice_id','created_at','history','id','proposal','reviews','rpl','source_hash','updated_at','version'],
        'training_sessions'=>['breaks','cancellation_reason','created_at','created_by','delivered_learning','end_time','history','id','members','name','planned_learning','session_date','start_time','state','tutor_group_id','updated_at','version'],
        'training_topics'=>['apprentice_id','area','completion_date','created_at','draft','evidence_stage','files','history','id','level','planned_hours','status','subject','submissions','topic','updated_at','version'],
        'tutor_groups'=>['active','created_at','id','name','programme_id','record_version','reference','tutor_id','updated_at'],
        'users'=>['active','auth_version','created_at','email','email_verified_at','id','name','password','record_version','remember_token','role','subject_scope','updated_at'],
        'workplace_support_share_events'=>['actor_id','created_at','event','id','share_id','share_version'],
        'workplace_support_shares'=>['acknowledged_at','apprentice_id','checked_at','checked_by','created_at','decided_at','expires_at','grant_id','id','learner_id','payload','payload_hash','permission','plan_id','prepared_by','recipient_id','request_token','source','source_hash','state','updated_at','version','withdrawn_at'],
        'apprentice_intake_batches'=>['id','request_token','created_by','state','version','source','source_hash','receipt','review_note','reviewed_by','reviewed_at','expires_at','created_at','updated_at'],
        'apprentice_intake_events'=>['id','batch_id','actor_id','event','record_version','payload','created_at'],
        'apprentice_setup_cases'=>['id','apprentice_id','issue','state','version','owner_id','due_on','details','details_hash','source_hash','proof','decided_by','decided_at','created_at','updated_at'],
        'apprentice_setup_case_events'=>['id','case_id','actor_id','event','payload','created_at'],
        'timetable_change_batches'=>['id','request_token','created_by','tutor_group_id','mode','state','version','source','source_hash','receipt','review_note','reviewed_by','reviewed_at','expires_at','created_at','updated_at'],
        'timetable_change_events'=>['id','batch_id','actor_id','event','record_version','payload','created_at'],
        'register_followup_cases'=>['id','training_session_id','owner_id','created_by','state','version','details','details_hash','source_hash','proof','decided_by','decided_at','due_on','created_at','updated_at'],
        'register_followup_events'=>['id','case_id','actor_id','event','payload','created_at'],
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
