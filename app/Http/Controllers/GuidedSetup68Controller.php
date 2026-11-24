<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,ApprenticeSetupDraft68 as Draft,ProgrammeSetupTemplate68 as Template,Employer,User,TutorGroup};
use App\Support\{GuidedSetup68 as Setup,Rpl68,LearningAccess,DirectoryCatalog};
use Illuminate\Http\Request;
class GuidedSetup68Controller extends Controller {
 private function allow(Request $r):void {abort_unless($r->user()?->can('create',Apprentice::class),403);}
 public function index(Request $r){$this->allow($r);return response()->view('setup68.index',['drafts'=>Draft::orderByDesc('updated_at')->paginate(20)])->header('Cache-Control','no-store, private');}
 public function create(Request $r){$this->allow($r);$d=Setup::start($r->user(),(int)$r->session()->get('auth_version'));return to_route('setup68.show',[$d,1]);}
 public function show(Request $r,Draft $draft,int $step=1){$this->allow($r);abort_unless(isset(Setup::STEPS[$step]),404);return $this->page($r,$draft,$step);}
 private const KSB_TYPES = ['K' => 'Knowledge', 'S' => 'Skills', 'B' => 'Behaviours'];

 private function ksbFilters(Request $request): array
 {
  $query = $request->query();
  $type = $query['ksb_type'] ?? null;
  $search = $query['search'] ?? '';
  $block = filter_var($query['block'] ?? null, FILTER_VALIDATE_INT, [
   'options' => ['min_range' => 1, 'max_range' => 11],
  ]);

  return array_filter([
   'ksb_type' => is_string($type) && isset(self::KSB_TYPES[$type]) ? $type : null,
   'search' => is_string($search) ? trim($search) : '',
   'block' => $block === false ? null : $block,
   'adjusted' => filter_var($query['adjusted'] ?? null, FILTER_VALIDATE_BOOLEAN) ? '1' : null,
  ], fn ($value) => $value !== null && $value !== '');
 }

 private function page(Request $r, Draft $draft, int $step, array $extra = [])
 {
  $p = $draft->payload;
  $review = Setup::review($draft);
  $t = $review['template'];
  $ksbFilters = $this->ksbFilters($r);
  $ksbTypes = self::KSB_TYPES;
  $search = mb_strtolower($ksbFilters['search'] ?? '');
  $type = $ksbFilters['ksb_type'] ?? null;
  $block = $ksbFilters['block'] ?? null;
  $adjusted = isset($ksbFilters['adjusted']);
  $ksbs = array_values(array_filter($t?->template['ksbs'] ?? [], fn ($ksb) =>
   ($type === null || str_starts_with($ksb['code'], $type))
   && ($search === '' || str_contains(mb_strtolower($ksb['code'].' '.$ksb['description']), $search))
   && ($block === null || in_array($block, $ksb['blocks'], true))
   && (!$adjusted || ($p['rpl']['adjustments'][$ksb['code']] ?? 0) > 0)
  ));
  $needle=mb_strtolower(trim((string)$r->query('employer_search','')));$employers=$needle!==''?Employer::where('active',true)->where(fn($q)=>$q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'",['%'.str_replace(['!','%','_'],['!!','!%','!_'],$needle).'%'])->orWhere('reference',$needle))->limit(30)->get():collect();if(!empty($p['employer']['id']))$employers=$employers->merge(Employer::whereKey($p['employer']['id'])->get())->unique('id');
  return response()->view('setup68.wizard',[...compact('draft','step','p','review','ksbs','employers','ksbTypes','ksbFilters'), 'templates'=>Template::where('active',true)->get()->filter(fn($x)=>Setup::template(['programme'=>['template_id'=>$x->id]])!==null),'markers'=>json_decode(file_get_contents(resource_path('data/inclusion-markers.json')),true),'tutors'=>User::where('role','tutor')->where('active',true)->get(),'groups'=>TutorGroup::where('active',true)->get(),'staffOptions'=>collect(['mentor_id'=>'mentor','careers_adviser_id'=>'careers_adviser','maths_tutor_id'=>'maths_tutor','english_tutor_id'=>'english_tutor'])->mapWithKeys(fn($role,$field)=>[$field=>DirectoryCatalog::options(\App\Support\StaffDuties::query(User::query(),$role))])->all(),...$extra])->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');
 }
 public function save(Request $r,Draft $draft,int $step){$this->allow($r);$r->validate(['version'=>['required','integer','min:1'],'next'=>['nullable','integer','between:1,7']]);$input=$r->input('data',$step===5&&$r->has('employer_search')?($draft->payload['employer']??[]):[]);abort_unless(is_array($input),422);
  if ($step === 4 && $r->input('action') === 'save_plan_dates') {
   $d = Setup::savePlanDates($r->user(), (int) $r->session()->get('auth_version'), $draft, $r->integer('version'), $input);
   return to_route('setup68.show', [$d, in_array($r->integer('next'),[6,7],true) ? 7 : 4])->with('status', 'Training Plan dates saved for setup confirmation.');
  }
  if($step===4){$current=$draft->payload['rpl']??[];$action=$r->input('action','save');if($action==='none')$input=['mode'=>'none'];
   elseif ($action === 'record_rpl') {
    $input = $this->rplEntry($r, $draft);
   }
   elseif(in_array($action,['batch_preview','batch_apply'],true)){
    $v=$r->validate(['codes'=>['required','array','min:1','max:300'],'codes.*'=>['required','string'],'percent'=>['required','integer','between:0,100']]);if($v['percent']%10!==0)Rpl68::fail('Choose a 10-percentage-point reduction.');abort_if($draft->version!==$r->integer('version'),409,'Draft changed. Reload the KSB list.');$t=Setup::template($draft->payload);if(!$t)Rpl68::fail('Select a programme first.');$codes=array_values(array_unique($v['codes']));if(array_diff($codes,array_column($t->template['ksbs'],'code')))Rpl68::fail('Select KSBs from this programme only.');$batch=['codes'=>$codes,'percent'=>(int)$v['percent'],'binding'=>Rpl68::hash([$draft->version,$codes,(int)$v['percent'],$t->source_hash])];
    if($action==='batch_preview'){$proposed=$current;foreach($codes as $code)$proposed['adjustments'][$code]=(int)$v['percent'];return $this->page($r,$draft,4,['batch'=>$batch,'batch_calculation'=>Rpl68::calculate($t->template,$proposed)]);}if($r->input('binding')!==$batch['binding'])Rpl68::fail('Preview these KSB changes again.');$input=[...$current,'mode'=>'applies','remaining_confirmed'=>false];foreach($codes as $code){if($v['percent']===0||$v['percent']==='0')unset($input['adjustments'][$code]);else $input['adjustments'][$code]=(int)$v['percent'];}
   }else{$input=[...$current,...$input,'remaining_confirmed'=>$r->boolean('data.remaining_confirmed')];}
  }
  $d=Setup::save($r->user(),(int)$r->session()->get('auth_version'),$draft,$r->integer('version'),$step,$input);$next=$r->integer('next')?:$step;if($step===1&&Setup::matches($d->payload['details']??[],$d->apprentice_id))$next=1;
  $returnFilters = $step === 4 && $next === 4 ? $this->ksbFilters($r) : [];
  if ($step === 5 && $r->has('employer_search')) {
   $returnFilters['employer_search'] = mb_substr((string) $r->input('employer_search'), 0, 100);
  }
  return to_route('setup68.show', [$d, $next, ...$returnFilters])->with('status',$step===4&&($r->input('action')==='batch_apply')?'Reductions saved to this draft. Check the revised OTJ hours before continuing.':($next===1&&$step===1?'Draft saved. Review any possible existing record before continuing.':'Draft saved.'));
 }
 private function rplEntry(Request $request, Draft $draft): array
 {
  $data = $request->validate([
   'data.mode' => ['required', 'in:none,applies'],
  ])['data'];

  $template = Setup::template($draft->payload);
  if (! $template || $template->source_hash !== ($draft->payload['programme']['template_hash'] ?? null)) {
   Rpl68::fail('Select the current programme template in Step 3 before recording prior learning.');
  }

  if ($data['mode'] === 'none') {
   return ['mode' => 'none'];
  }

  $continuing = $request->integer('next') !== 4;
  $values = $request->validate([
   'data.assessment_ref' => [$continuing ? 'required' : 'nullable', 'string', 'max:1000'],
   'reductions' => ['nullable', 'array'],
   'reductions.*' => ['required', 'integer', 'between:0,100', 'multiple_of:10'],
  ]);
  $entered = $values['reductions'] ?? [];
  if (array_diff(array_keys($entered), array_column($template->template['ksbs'], 'code'))) {
   Rpl68::fail('Select KSBs from this programme only.');
  }
  $adjustments = array_replace($draft->payload['rpl']['adjustments'] ?? [], array_map('intval', $entered));
  $adjustments = array_filter($adjustments, fn ($percent) => $percent > 0);
  ksort($adjustments, SORT_NATURAL);

  if ($continuing && $adjustments === []) {
   Rpl68::fail('Record at least one approved reduction, or choose No relevant prior learning.');
  }

  return [
   'mode' => 'applies',
   'assessment_ref' => $values['data']['assessment_ref'] ?? null,
   'adjustments' => $adjustments,
   'remaining_confirmed' => $continuing,
  ];
 }
 public function submit(Request $r,Draft $draft){$this->allow($r);$r->validate(['version'=>['required','integer'],'confirmed'=>['accepted']]);$d=Setup::submit($r->user(),(int)$r->session()->get('auth_version'),$draft,$r->integer('version'));return to_route('setup68.show',[$d,7])->with('status','Setup ready. Confirm it from Step 7.');}
 public function confirm(Request $r,Draft $draft){$this->allow($r);$v=$r->validate(['version'=>['required','integer'],'confirmed'=>['accepted'],'practical_end'=>['nullable','date_format:Y-m-d'],'programme_end'=>['nullable','date_format:Y-m-d','after:practical_end']]);$d=Setup::confirm($r->user(),(int)$r->session()->get('auth_version'),$draft,(int)$v['version'],$v['practical_end']??null,$v['programme_end']??null);return to_route('setup68.show',[$d,7])->with('status','Setup complete. The approved training plan and OTJ target are now linked to the apprentice.');}
 public function reopen(Request $r,Draft $draft){$this->allow($r);$v=$r->validate(['version'=>['required','integer'],'reason'=>['required','string','max:1000']]);$d=Setup::reopen($r->user(),(int)$r->session()->get('auth_version'),$draft,$v['version'],$v['reason']);return to_route('setup68.show',[$d,4])->with('status','Same RPL record reopened. The current plan applies until staff confirm the correction.');}
}
