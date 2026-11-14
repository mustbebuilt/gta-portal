#!/usr/bin/env python3
"""Build programme-specific portal schemas from the supplied blank PDF packs.

Development only: requires pypdf and PyMuPDF. Does not touch saved learner work.
Usage: python tools/import_programme_tasks.py heavy-vehicle /absolute/source.zip
       python tools/import_programme_tasks.py paint /absolute/source.zip
"""
import argparse
import copy
import hashlib
import io
import json
import logging
import re
import zipfile
from pathlib import Path

import fitz
from pypdf import PdfReader

ROOT = Path(__file__).resolve().parents[1]
logging.getLogger('pypdf').setLevel(logging.ERROR)


def dump(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def printed_label(page, widget, name, meta):
    rect = widget.rect
    if re.match(r'^b\d+_t\d+_', name) and str(meta.get('/TU', '')).startswith('b'):
        # Panel tooltips retain old internal names; use the printed label instead.
        meta = {k: v for k, v in meta.items() if k != '/TU'}
    if name.startswith('b10t3_'):
        suffix = name[len('b10t3_'):]
        header = re.fullmatch(r'cl(\d+)_(name|id|date|job)', suffix)
        if header:
            return f"Checklist {header[1]} - " + {'name': 'Apprentice', 'id': 'Vehicle / trailer ID', 'date': 'Date', 'job': 'Job card / evidence ref'}[header[2]]
        check = re.fullmatch(r'[abct]_([ABCT]\d+)_(done|na)', suffix)
        if check:
            row = ' '.join(page.get_textbox(fitz.Rect(40, rect.y0 - 4, 490, rect.y1 + 7)).split())
            assert check[1] in row, (name, row)
            return row + (' - Done' if check[2] == 'done' else ' - N/A')
        tyre = re.fullmatch(r'tyre_(trailer1|trailer2|front|rear|extra)_([0-3])', suffix)
        if tyre:
            row = {'trailer1': 'Trailer axle 1', 'trailer2': 'Trailer axle 2', 'front': 'Front', 'rear': 'Rear', 'extra': 'Additional / position'}[tyre[1]]
            return row + ' - ' + ['Offside outer', 'Offside inner', 'Nearside outer', 'Nearside inner'][int(tyre[2])] + ' - tread depth (mm)'
        reading = re.fullmatch(r'(air|steering|other)_(actual|spec)', suffix)
        if reading:
            row = {'air': 'A10 Air pressure / warning and build-up', 'steering': 'A13 Steering free play or control check', 'other': 'Other measured check and specification'}[reading[1]]
            return row + (' - Actual / units' if reading[2] == 'actual' else ' - Criterion / ref')
        finding = re.fullmatch(r'finding_(\d+)_(ref|evidence|action)', suffix)
        if finding:
            return f"Finding {int(finding[1])+1} - " + {'ref': 'Checklist ref / exact location', 'evidence': 'Finding and evidence', 'action': 'Urgency / action'}[finding[2]]
        labels = {'manual': 'DVSA manual / section and access date', 'limitations': 'Uncompleted checks / reason', 'status_serviceable': 'Inspection status - Serviceable', 'status_defect': 'Inspection status - Defect / action', 'status_unknown': 'Inspection status - Not confirmed', 'trailer_findings': 'Trailer findings / checks not completed'}
        if suffix in labels:
            return labels[suffix]
    if re.search(r'(?:measure|tech)_\d+_(?:left|right)$', name):
        width = 150 if re.match(r'^b0?\d+_t0?\d+_tech_', name) and str(widget.field_label or '').startswith('b') else 112
        clip = fitz.Rect(rect.x0 - width, rect.y0 - 2, rect.x0 - 2, rect.y1 + 4)
    elif re.search(r'(?:knowledge_|_q|_kq_)\d+$', name):
        clip = fitz.Rect(rect.x0 - 1, rect.y0 - 48, rect.x1 + 1, rect.y0 + 2)
    elif re.search(r'(?:observation_|_obs_)\d+$', name):
        end = 293 if rect.x0 < 300 else 556
        clip = fitz.Rect(rect.x1 + 3, rect.y0 - 3, end, rect.y1 + 9)
    else:
        tooltip = str(meta.get('/TU', ''))
        if tooltip and tooltip.lower().replace(' ', '_') != name.lower():
            return tooltip
        # Pick the nearest complete printed label block, excluding neighbouring columns.
        candidates = []
        for bi, block in enumerate(page.get_text('dict')['blocks']):
            for line in block.get('lines', []):
                x0, y0, x1, y1 = line['bbox']
                if x0 >= rect.x0 - 2 and x1 <= rect.x1 + 2 and rect.y0 - 52 <= y1 <= rect.y0 + 4:
                    candidates.append((y1, bi, ''.join(span['text'] for span in line['spans'])))
        if candidates:
            nearest = max(candidates, key=lambda item: item[0])
            return ' '.join(item[2].strip() for item in sorted(candidates) if item[1] == nearest[1])
        return name.replace('_', ' ').capitalize()
    lines = [s.strip() for s in page.get_textbox(clip).splitlines() if s.strip()]
    text = ' '.join(lines)
    if re.search(r'(?:knowledge_|_q|_kq_)\d+$', name):
        number = re.search(r'(\d+)$', name).group(1)
        match = re.search(r'\b' + number + r'\.\s*', text)
        if match:
            text = text[match.start():]
    return text or name.replace('_', ' ').capitalize()


def role_and_type(name, kind, meta):
    key = re.sub(r'^b\d+_t\d+_', '', name)
    role = 'apprentice'
    if kind == 'impact':
        if key.startswith(('confirmation_', 'review_', 'reviewer_', 'mentor_signoff', 'trainer_signoff')) or key in ['actions', 'agreed_actions', 'signoff_date_2', 'signoff_date_3']:
            role = 'trainer'
        if key in ['confirmation_name_1', 'confirmation_date_1']:
            role = 'apprentice'
    elif key.startswith(('observation', 'obs_', 'assessor_', 'assessment_', 'decision', 'feedback', 'evidence_ref')) or key == 'date':
        role = 'trainer'
    if key.startswith('decision'):
        return role, 'decision', key
    if re.search(r'(start|end)_score_\d+$', key):
        return role, 'score', key
    if '/Btn' == meta.get('/FT'):
        return role, ('choice' if int(meta.get('/Ff', 0)) & 32768 else 'checkbox'), key
    # PDF text fields were deliberately made multiline: dates stay typed text too.
    return role, 'text', ('apprentice_name' if key in ['detail_0_left', 'details_0_left', 'confirmation_name_1'] else key)


def build(programme, archive, impact_archive=None):
    source = Path(archive)
    pdfs = ROOT / 'resources/task-pdfs'
    data = ROOT / 'resources/data'
    old = json.loads((data / 'autocare-catalogue.json').read_text())
    templates, mapping, tasks, receipts = {}, {}, [], []
    sources = [source] + ([Path(impact_archive)] if impact_archive else [])
    assert not impact_archive or programme == "panel"
    for current_source in sources:
        with zipfile.ZipFile(current_source) as z:
            for path in sorted(z.namelist()):
                if not path.endswith('.pdf'):
                    continue
                name = Path(path).name
                block = int(re.search(r'Block_(\d+)', name).group(1))
                kind = 'impact' if 'Learning_Impact' in name else 'practical'
                task_number = 5 if kind == 'impact' else int(re.search(r'Task_(\d+)', name).group(1))
                raw = z.read(path)
                reader = PdfReader(io.BytesIO(raw))
                doc = fitz.open(stream=raw, filetype='pdf')
                source_text = '\n\n'.join(p.get_text(sort=True) for p in doc)
                first_text = doc[0].get_text()
                title = 'Learning impact record' if kind == 'impact' else re.sub(r'_v[\d.]+(?:_Scenarios)?\.pdf$', '', re.split(r'Task_\d+_', name)[1]).replace('_', ' ')
                theme = first_text.splitlines()[1] if kind == 'impact' else title
                fields, labels = [], []
                all_meta = reader.get_fields() or {}
                seen = set()
                for page_number, page in enumerate(doc, 1):
                    for widget in page.widgets() or []:
                        field_name = widget.field_name
                        if field_name in seen:
                            continue
                        seen.add(field_name)
                        meta = all_meta[field_name]
                        role, field_type, key = role_and_type(field_name, kind, meta)
                        label = printed_label(page, widget, field_name, meta)
                        if programme == 'panel':
                            if key == 'evidence_refs':
                                role = 'apprentice'
                            panel_labels = {'vehicle_make': 'Vehicle make', 'mileage': 'Mileage', 'vehicle_model': 'Vehicle model', 'chassis_number': 'Chassis number', 'vehicle_reg': 'Vehicle reg', 'job_card': 'Job card number', 'panel_area': 'Panel / area', 'apprentice_group': 'Apprentice group', 'apprentice_name': 'Apprentice name', 'apprentice_date': 'Date signed', 'assessor_name': 'Assessor name', 'assessor_pin': 'Assessor PIN', 'assessor_date': 'Date signed', 'assessment_ref': 'Assessment / evidence reference'}
                            label = panel_labels.get(key, label)
                        if programme == 'panel' and kind == 'impact':
                            label = {'confirmation_name_1': 'Apprentice name / signature', 'confirmation_date_1': 'Apprentice confirmation date', 'confirmation_name_2': 'Workplace mentor name / signature', 'confirmation_date_2': 'Workplace mentor confirmation date', 'confirmation_name_3': 'GTA trainer name / signature', 'confirmation_date_3': 'GTA trainer confirmation date'}.get(key, label)
                            if key.startswith('confirm_'):
                                role = 'trainer'
                                label = {'confirm_1': 'Improved knowledge and understanding', 'confirm_2': "Safe application of skills within the apprentice's role", 'confirm_3': 'Relevant professional behaviours demonstrated', 'confirm_4': 'Further practice, support or assessment required'}[key]
                            if key == 'needs_practice':
                                label = 'What still needs practice, support or assessment? Explain each unassessed START or END score here.'
                        if field_type == 'decision':
                            label = 'Tutor assessment decision'
                        if key == 'location':
                            label = 'Task completed at'
                        score = re.search(r'(start|end)_score_(\d+)$', field_name)
                        if score:
                            label = f'Area {score[2]} - {score[1].upper()} score /10'
                        options = []
                        if field_type == 'choice':
                            options = [str(x).lstrip('/') for x in meta.get('/_States_', []) if str(x) != '/Off']
                        section = 'Tutor / observer record' if role == 'trainer' else ('Continuation evidence' if field_name.startswith('cont_') else ('Learning reflection' if kind == 'impact' else 'Practical work and knowledge'))
                        if field_name.startswith('b10t3_'):
                            section = f'Inspection checklist - source page {page_number}'
                        if kind == 'impact' and score:
                            section = 'START and END reflection scores'
                        help_text = ''
                        if score:
                            score_left = min(w.rect.x0 for w in page.widgets() if 'score' in w.field_name)
                            help_text = ' '.join(b[4].strip().replace('\n', ' ') for b in sorted(page.get_text('blocks'), key=lambda b: b[1]) if b[0] >= 98 and b[2] <= score_left - 4 and widget.rect.y0 - 20 <= b[1] <= widget.rect.y1 + 15)
                        if programme == 'panel' and kind == 'impact' and score:
                            help_text = ' '.join(page.get_textbox(fitz.Rect(98, widget.rect.y0 - 8, 440, widget.rect.y1 + 8)).split())
                        fields.append({'name': field_name, 'key': key, 'label': label, 'type': field_type, 'role': role, 'section': section, 'options': options, 'help': help_text, 'long': field_type == 'text', 'pdfType': str(meta.get('/FT', '')), 'sourcePage': page_number})
                        labels.append({'name': field_name, 'label': label, 'role': role, 'type': field_type, 'page': page_number})
                assert set(all_meta) == seen, (name, set(all_meta) - seen)
                assert any(f['key'] in ['apprentice_name', 'sign_apprentice_name', 'apprentice_signoff'] and f['role'] == 'apprentice' for f in fields), name
                stored = programme + '--' + name
                (pdfs / stored).write_bytes(raw)
                version = re.search(r'_v([\d.]+)(?:_Scenarios)?\.pdf$', name).group(1)
                t = {'filename': name, 'sourcePdfFilename': stored, 'kind': kind, 'catalogueId': programme, 'sourceBlock': block, 'sourceTask': task_number, 'title': title, 'version': version, 'instructions': first_text, 'sourceText': source_text, 'supplementaryPages': [{'page': i + 1, 'text': doc[i].get_text()} for i in range(1, len(doc)) if (i >= 3 or 'inspection checklist |' in doc[i].get_text()) and 'Task answer continuation' not in doc[i].get_text()], 'fields': fields, 'areas': [], 'sourceTemplateHash': hashlib.sha256(raw).hexdigest()}
                # Hash the actual portal schema separately from unchanged source PDF bytes.
                t['exclusiveCheckboxGroups'] = [[f['name'], f['name'][:-5] + '_na'] for f in fields if f['name'].startswith('b10t3_') and f['name'].endswith('_done')]
                if any(f['name'] == 'b10t3_status_serviceable' for f in fields):
                    t['exclusiveCheckboxGroups'].append(['b10t3_status_serviceable', 'b10t3_status_defect', 'b10t3_status_unknown'])
                if programme == 'panel' and kind == 'impact':
                    t['unassessedReasonField'] = 'needs_practice'
                t['sha256'] = hashlib.sha256(json.dumps(t, ensure_ascii=False, sort_keys=True).encode()).hexdigest()
                ref = f'{programme}-b{block}-t{task_number}'
                templates[stored] = t
                mapping[ref] = stored
                tasks.append({'id': ref, 'block': block, 'task': task_number, 'title': f'Task {task_number}: {title}', 'theme': theme, 'template': stored})
                receipts.append({'archiveMember': path, 'storedFilename': stored, 'sourcePdfHash': t['sourceTemplateHash'], 'pages': len(doc), 'fields': labels})
                doc.close()
    pending = programme == 'panel' and not impact_archive
    expected_pdfs = 20 if pending else 30
    assert len(receipts) == expected_pdfs, 'Unexpected source pack PDF count.'
    for block in range(1, 11):
        assert sorted(t['task'] for t in tasks if t['block'] == block) == ([3, 4] if pending else [3, 4, 5])
        theme = next(t['theme'] for t in tasks if t['block'] == block and t['task'] == (3 if programme == 'panel' else 5))
        if pending:
            ref = f'panel-b{block}-t5'
            filename = f'panel-block-{block}-reflection-source-required'
            t = {'filename': filename, 'kind': 'source-pending', 'catalogueId': 'panel', 'sourceBlock': block, 'sourceTask': 5, 'title': 'Learning reflection - source required', 'version': 'pending', 'instructions': 'The Panel learning reflection source has not been loaded. This placeholder cannot be answered, submitted or approved. It does not confirm block completion. An administrator can record a reasoned sequence exception to allow work in a later block.', 'fields': [], 'pdfAvailable': False}
            t['sha256'] = hashlib.sha256(json.dumps(t, sort_keys=True).encode()).hexdigest()
            templates[filename] = t
            mapping[ref] = filename
            tasks.append({'id': ref, 'block': block, 'task': 5, 'title': 'Task 5: Learning reflection - source required', 'theme': theme, 'template': filename})
        for number in [1, 2]:
            t = copy.deepcopy(old['templates'][old['tasks'][f'b{block}-t{number}']])
            t['catalogueId'] = programme
            if number == 1:
                t['filename'] = f'{programme}-block-{block}-paper-result-v1.0'
                t['testTitleDefault'] = f'Block {block} - {theme}: Knowledge Assessment'
                t['instructions'] = 'Paper knowledge assessment. The tutor records the actual test title, marks achieved, marks available, outcome and feedback. No questions or pass threshold are inferred.'
                t['sourceText'] = t['instructions']
                t['sha256'] = hashlib.sha256(json.dumps(t, sort_keys=True).encode()).hexdigest()
            else:
                t['sourceTemplateHash'] = t['sha256']
            ref = f'{programme}-b{block}-t{number}'
            templates[t['filename']] = t
            mapping[ref] = t['filename']
            tasks.append({'id': ref, 'block': block, 'task': number, 'title': f'Task {number}: {t["title"]}', 'theme': theme, 'template': t['filename']})
    tasks.sort(key=lambda t: (t['block'], t['task']))
    dump(data / f'{programme}-tasks.json', tasks)
    dump(data / f'{programme}-catalogue.json', {'tasks': mapping, 'templates': templates})
    dump(data / f'{programme}-source-receipt.json', {'archive': source.name, 'archiveSha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'sourcePdfCount': len(receipts), 'taskCount': 50, 'sourcePendingTasks': 10 if pending else 0, 'sources': [{'archive': x.name, 'sha256': hashlib.sha256(x.read_bytes()).hexdigest()} for x in sources], 'sharedWorkplaceJobCard': 'Task_2_Apprentice_Workplace_Job_Card_v2.2.pdf', 'paperAssessments': 'Result recording only; question papers outside this pack', 'pdfs': receipts})
    print(f'{programme}: imported {len(receipts)} unchanged PDFs; 50 task slots; {10 if pending else 0} source-pending slots')


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('programme', choices=['heavy-vehicle', 'paint', 'panel'])
    p.add_argument('archive')
    p.add_argument('--impact-archive')
    a = p.parse_args()
    build(a.programme, a.archive, a.impact_archive)
