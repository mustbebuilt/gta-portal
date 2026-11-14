# GTA Laravel v0.16.0 - Individual block calendars

Added an individual delivery-plan page for all four loaded programme versions. Administrators/CEO can save partial calendars covering twelve numbered blocks. Actual dates are entered explicitly: historical PDF week labels are not used to infer an individual calendar.

Server validation rejects reversed dates, overlapping/out-of-order blocks, dates before recorded first learning, negative hours and training hours assigned to the EPA assessment window. Gaps remain possible for holidays/breaks. Block 11/12 dates are planning only; their criterion content is not invented. Occupational hours stay separate from Maths/English and actual delivered OTJ.

Calendars have row version checks and transactional before/after audit. Calendar saves do not update profile dates, task deadlines, attendance, targets or funding. Managers see the draft; the apprentice and assigned tutor see the last applicable provider-approved snapshot only.

Executed consolidated checks and limits: TEST_RESULTS.json at v0.18.0. No intermediate source ZIP is represented as independently tested/deployed.
