<?php

return [
    'support_targets' => [
        'Attendance and contact' => [
            'Contact after absence' => ['Contact the apprentice after the missed session and agree the next contact or return date.', 'Record the outcome of the contact attempt and the agreed next step in the support record.'],
            'Check in after no contact' => ['Make the agreed contact attempt using the approved contact route and record the next step.', 'Record the date, channel and outcome of the attempt. Escalate through the existing process if contact is still missing.'],
            'Agree a return to training' => ['Agree the date and arrangements for returning to the next suitable session.', 'Record the agreed session and arrangements, then check the next saved register.'],
            'Review attendance pattern' => ['Review the last two completed weekly registers with the apprentice and agree one step to support attendance.', 'Record the sessions checked and one agreed action with its owner and date.'],
            'Resolve a transport barrier' => ['Agree a travel plan for the next scheduled session, including a backup arrangement.', 'Record the agreed plan and check whether the apprentice attended the selected session.'],
            'Catch up missed learning' => ['Agree a catch-up activity for the missed learning and check it by the due date.', 'Record the activity and the tutor’s check of the completed work or demonstration.'],
        ],
        'Workplace and retention' => [
            'Employer check-in' => ['Contact the authorised workplace representative to agree the next step on the current issue.', 'Record the contact outcome and one agreed action or a dated follow-up.'],
            'Protect release for training' => ['Agree the next training release date with the employer and confirm it with the apprentice.', 'Record the date and employer confirmation. Check the saved register after the session.'],
            'Update workplace contact' => ['Confirm the current workplace contact and update the employer record through the approved process.', 'Record the verified contact or the outstanding verification step.'],
            'Support a change of employer' => ['Agree the next training and workplace check-in during the employer change.', 'Record the agreed check-in and the person responsible for following it up.'],
            'Plan for an apprentice without an employer' => ['Meet or contact the apprentice to agree one employment search action and the next check-in.', 'Record the action taken and the date of the next check-in.'],
            'Check risk of leaving' => ['Discuss the current barrier with the apprentice and agree a specific next step to support continuation.', 'Record the apprentice’s view where available and the agreed action or existing escalation route.'],
            'Review return after a break' => ['Confirm the proposed return date and the support needed for the first session back.', 'Record the agreed return arrangements and verify the outcome after the planned session.'],
        ],
        'Learning and support' => [
            'Put an adjustment in place' => ['Agree and trial one adjustment in the next suitable session.', 'Record the adjustment used and the apprentice’s feedback, or why the trial could not take place.'],
            'Check whether support is helping' => ['Review the agreed support with the apprentice and decide whether to keep, change or end it.', 'Record feedback and the agreed decision in the support record.'],
            'Book a support check-in' => ['Agree and hold one support check-in with the apprentice by the due date.', 'Record the date, outcome and agreed next step using the restricted record where needed.'],
            'Restore portal or learning access' => ['Resolve the reported access barrier so the apprentice can open the agreed learning activity.', 'Record a successful access check with the apprentice or the next technical action and owner.'],
            'Agree a manageable workload' => ['Agree the first priority activity and a realistic completion date with the apprentice.', 'Record the named activity and check the agreed evidence by the deadline.'],
            'Arrange Maths or English support' => ['Agree one priority topic and a support session with the subject tutor.', 'Record the topic and session, then check the agreed practice or demonstration.'],
            'Review an agreed referral' => ['Check whether the agreed referral or handover has been received and confirm the next step.', 'Record the referral status and the next contact in the appropriate restricted record.'],
        ],
    ],
    'learning_targets' => [
        'Training and evidence' => [
            'Current block practice' => ['Complete the agreed practice activity from the current training block.', 'Tutor checks the finished activity against the agreed block requirements.', 'observation', 'block'],
            'Workplace skill demonstration' => ['Demonstrate the agreed workplace skill during the next suitable work task.', 'The agreed observer checks the demonstration against the relevant job card or work standard.', 'observation', 'workplace'],
            'Upload workplace evidence' => ['Upload one piece of agreed workplace evidence with a short explanation of your own contribution.', 'Tutor can identify the task, date and apprentice’s contribution from the upload.', 'portal', 'workplace'],
            'Complete a job card' => ['Complete the agreed job card and upload or present it for review.', 'Tutor checks that the work steps, findings and any follow-up are recorded.', 'observation', 'workplace'],
            'Act on tutor feedback' => ['Complete the agreed correction from the tutor’s feedback and present the revised evidence.', 'Tutor checks the specific correction against the feedback.', 'portal', 'correction'],
            'Complete a knowledge check' => ['Complete the agreed knowledge check for the selected topic.', 'Tutor checks the answers and records any areas that need further practice.', 'worksheet', 'block'],
            'Practise a Maths topic' => ['Complete the agreed practice on the named Maths topic.', 'Subject tutor checks the completed practice and the working for one selected question.', 'worksheet', 'englishmaths'],
            'Practise an English topic' => ['Complete the agreed practice on the named English topic.', 'Subject tutor checks the completed work against the agreed topic criteria.', 'worksheet', 'englishmaths'],
            'Complete agreed OTJ catch-up' => ['Complete the agreed off-the-job catch-up activity and provide evidence of the learning.', 'Tutor checks the evidence and records eligible hours through the existing OTJ process.', 'portal', 'otj'],
            'Progress review follow-up' => ['Complete the specific action agreed at the progress review and bring the evidence to the next check.', 'The responsible tutor checks the agreed evidence and records the outcome.', 'discussion', 'review'],
        ],
    ],
    'marking_feedback' => [
        'Evidence and corrections' => [
            'Missing evidence' => ['Add the missing evidence for [[add detail]], then resubmit.'],
            'Clearer photo or file' => ['Provide a clearer photo or file showing [[add detail]] so it can be checked.'],
            'Explain your own contribution' => ['Explain what you personally did for [[add detail]] and which part of the evidence shows it.'],
            'Complete an unanswered part' => ['Complete the unanswered part covering [[add detail]], then resubmit.'],
            'Correct a specific point' => ['Correct [[add detail]] and explain what you changed before resubmitting.'],
            'Explain your method' => ['Show the working or explain the steps you took for [[add detail]].'],
            'Check against the instructions' => ['Compare your work with the requirement for [[add detail]] and update the submission.'],
            'Add an observation or job record' => ['Add the agreed observation or job record for [[add detail]].'],
        ],
        'Strengths and next steps' => [
            'Recognise what went well' => ['Your submission clearly shows [[add detail]]. Keep using this approach in similar work.'],
            'Build on a strength' => ['Build on this piece of work by practising [[add detail]] in your next activity.'],
            'Discuss at the next session' => ['Bring [[add detail]] to the next session so we can check it together.'],
            'Record the feedback discussion' => ['We discussed the work and agreed this next step: [[add detail]].'],
        ],
    ],
    'review_outcomes' => [
        'Marker review' => [
            'Monitor at the next review' => ['Keep the marker open and check progress at the next agreed review.'],
            'Continue current support' => ['Continue the agreed support and check its impact at the next review.'],
            'Review a change to support' => ['Review the support with the responsible staff member and record any agreed change.'],
            'Further information needed' => ['The review needs further information. Record who will obtain it and when it will be checked.'],
            'Review possible closure' => ['Review the closure criteria and record the reason separately if the marker can be closed.'],
        ],
    ],
    'register_corrections' => [
        'Attendance register' => [
            'Attendance code correction' => ['Corrected the attendance code using the checked session record.'],
            'Arrival or departure correction' => ['Corrected the recorded arrival or departure using the checked session record.'],
            'Eligible OTJ minutes correction' => ['Corrected eligible OTJ minutes after checking actual attendance, learning and exclusions.'],
            'Delivered learning correction' => ['Corrected the delivered learning after checking the tutor’s session record.'],
            'Cover handover correction' => ['Updated the register from the verified cover tutor handover.'],
        ],
    ],
    'register_cancellations' => [
        'Attendance register' => [
            'GTA closed' => ['Session cancelled because GTA was closed.'],
            'Tutor unavailable' => ['Session cancelled because tutor cover could not be arranged.'],
            'Room or workshop unavailable' => ['Session cancelled because the planned room or workshop was unavailable.'],
            'Timetable change' => ['Session cancelled following a confirmed timetable change.'],
            'Severe weather or disruption' => ['Session cancelled due to weather or travel disruption.'],
        ],
    ],
];
