<?php

namespace App\Http\Controllers;

use App\Support\SupportAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(Request $request): View
    {
        $role = $request->user()->role;
        $guides = [
            ['All users', 'Start here', 'Choose an area from the navigation. Open a record to see its source information. Save before leaving a form. A saved confirmation means the database accepted your changes. If a record changed, reopen it before editing again.'],
            ['All users', 'Understand the figures', 'Task approval, training-topic completion and qualification achievement are separate. Blue means achieved or approved in the named area. Red identifies an overdue item. Grey means not enough information to rate. No overall apprenticeship completion or inspection grade is inferred.'],
        ];
        if (in_array($role, ['admin','ceo'], true)) {
            $guides[] = ['Administration', 'Set up staff and adviser assignments', 'Use Portal setup for the order of work. Martin provisions your first named Admin or CEO account. Add staff once with their name, work email and primary role, then arrange sign-in separately. Edit each apprentice to select the main tutor, Careers adviser, Learning and Progress Mentor and maths or English tutor. Inclusion duties are managed under Support permissions. A Manager staff role is not an administrator account.'];
        }
        if ($role === 'apprentice') {
            $guides[] = ['Apprentice', 'Submit work', 'Open your training tasks. Save a draft while working, add any required evidence and confirm it is your own work before submitting. Submitted work is locked. If referred, read tutor feedback, update the task and resubmit. A topic evidence decision does not automatically complete training or a qualification.'];
            $guides[] = ['Apprentice', 'Your next step', 'Use your Action Plan to see the agreed owner, outcome and due date. Mark your action ready for tutor checking when you have met its success criteria. Careers discussions are private to you and authorised advisers. Your journey shows dated events already recorded in the portal.'];
        }
        if (in_array($role, ['admin', 'ceo'], true)) {
            $guides[] = ['Administration', 'Add and reopen an apprentice', 'Create the employer, programme, group and staff assignments in directories when required. Choose Add apprentice, enter the learner reference and known details, assign the correct staff and save. Reopen the saved profile to confirm it. Assign training tasks explicitly; do not use this as approval of an individual training plan.'];
            $guides[] = ['Administration', 'Reporting', 'Operational reporting opens the connected source records. Retention reporting separates completion from achievement and shows unknown historical information explicitly. Inspection Overview contains source links and approved anonymised case studies; it is a partial evidence overview, not an inspection grade.'];
        }
        if (in_array($role, ['admin', 'ceo', 'tutor'], true)) {
            $guides[] = ['Tutor', 'Mark work and register attendance', 'Open assigned apprentice tasks to record the whole-task decision and feedback. Marking is overdue after more than seven calendar days. Plan a group session, then record actual attendance, delivered subjects and time. Do not record expected learning as delivered learning. Monthly snapshots use observed evidence and Not observed where you cannot judge.'];
            $guides[] = ['Tutor', 'Set a SMART action', 'Describe one clear outcome, choose a measurable quantity or observable success criteria, record relevance, support, owner and a realistic due date. Agree it with the apprentice. To do, With tutor and Completed are different states. A referred-task action opens the original task and follows its source status.'];
        }
        if (in_array($role, ['admin', 'ceo', 'functional_skills_tutor'], true)) {
            $guides[] = ['Maths and English', 'Topics and qualifications', 'Assign only the apprentice’s required topics and planned hours. Zero planned hours means secure in that topic. Evidence, evidence acceptance and tutor-confirmed topic completion are separate steps. Record each qualification separately with its actual result, individually chosen start date and twelve-month target. Do not turn topic percentages into a qualification result.'];
        }
        if (in_array($role, ['admin', 'ceo', 'mentor', 'tutor'], true)) {
            $guides[] = ['Reviews', 'Prepare a review draft', 'Open Reviews and choose an assigned apprentice. Record the actual meeting information and discussion. Source snapshots help preparation but do not replace the whole individual training plan. Current review records are drafts only: signatures, completion, shared copies and completed-review scheduling are not yet enabled.'];
        }
        if (in_array($role, ['admin', 'ceo', 'careers_adviser'], true)) {
            $guides[] = ['Careers adviser', 'Record advice', 'Open Careers to see apprentices currently assigned to you. Choose the actual discussion type: career progression or employment support. Record goals, advice and outcome, then measurable actions with an owner and deadline. Employers do not receive access to these records.'];
        }
        if (SupportAccess::team($request->user())) {
            $guides[] = ['Inclusion', 'Follow up a concern', 'Open Support and retention. A new concern is separate from acknowledgement, classification and a support plan. Use the original inclusion markers, allocate an owner and record chronological weekly follow-ups and measurable targets. Keep approved teaching instructions separate from restricted case notes. Do not enter safeguarding case details. Use Questionnaires for the reviewed external responder links. Opening a link never creates a receipt or completes a check. Survey receipt and Inclusion Team checks are tracked separately from the external questionnaire.'];
        }
        if (! in_array($role, ['apprentice', 'employer'], true)) {
            $guides[] = ['Staff', 'Staff development privacy', 'Open Staff development for your own shared records and CPD. Assigned line managers and the CEO can access manager records. Ordinary administrator access does not automatically give access to another employee’s private HR information. Planned meetings are completed through a linked record of the matching type.'];
        }
        if (in_array($role, ['admin', 'ceo', 'tutor', 'apprentice'], true)) {
            $guides[] = ['Training plan', 'Read plan preparation', 'Training-plan preparation assembles existing profile, assigned topic and qualification sources. Its proposals are drafts, not an approved individual target or price. Read the block and KSB reference separately from task achievement. Only Administration and CEO can assess or review RPL; private evidence references are excluded from tutor and apprentice views.'];
            $guides[] = ['Extra training', 'Record additional training', 'Open Attendance and OTJ, then Extra training. Tutors record actual additional training with its time, exclusions, new learning and evidence. Outside normal hours needs the apprentice’s actual agreement and evidence of compensation received. The tutor then checks eligibility. Pending and withdrawn records add no hours. Overlapping recorded time cannot be counted twice.'];
        }
        if ($role === 'ceo') {
            $guides[] = ['CEO', 'Emergency access', 'Ordinary CEO oversight works without emergency override. Use CEO emergency access only when needed: record a reason, confirm your password and acknowledge the audit. The override enables restricted teaching instruction actions for your current session. End it when finished; signing out also ends it. Source, version and inactive-account checks still apply.'];
        }
        if ($role !== 'apprentice') {
            $guides[] = ['Case-study author', 'Show evidenced impact', 'Create an anonymous case study using the structured fields. Do not copy personal, health or safeguarding details. Include the starting point, support, recorded difference, source references, limitations and next check. A different administrator or CEO must approve the exact version before Inspection Overview can use it. Internal approval is not permission for external publication.'];
        }

        if (in_array($role, ['admin', 'ceo'], true)) {
            $guides[] = ['Administration', 'Release training blocks', 'New programme task allocations enable sequence controls. Open Training blocks and tasks, then Administrator block controls. A later block opens after all five source tasks in each previous block are approved. Record a reason for an exception. An exception releases work; it does not approve a task, KSB or training plan. Existing task sets are unchanged until you enable sequence controls.'];
        }
        if (in_array($role, ['admin', 'ceo', 'tutor'], true)) {
            $guides[] = ['Marking', 'Use the marking worklist', 'Open Marking worklist for submitted tasks. Tutors see only current main-tutor assignments; management sees the full queue. Overdue means more than seven calendar days since submission. Missing/future dates need checking. Administration can record in-portal reminders once per current submission and recipient. Marking a reminder seen does not approve the task; open the task for its normal tutor decision. No email is sent.'];
            $guides[] = ['Sessions', 'Repeat a plan or correct a register', 'Choose the number of weekly sessions when planning. Each week has a separate saved roster and empty register. Check closures and holidays individually. To change a saved actual register, reopen it, enter the correction reason and save. The previous marks stay in history; new minutes replace the old subtotal.'];
        }

        $guides[] = ['Your account', 'Change your password', 'Use Change password to enter your current password and choose a new passphrase of at least 12 characters. You will be signed out afterwards. Older sessions are invalidated. Live password recovery is not enabled in this checkpoint; contact your GTA administrator. Local recovery tests use an internal mail sink only.'];
        return view('help.index', compact('guides'));
    }
}
