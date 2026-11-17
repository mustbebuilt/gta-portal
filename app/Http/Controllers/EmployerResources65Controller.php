<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployerResources65Controller extends Controller
{
    private function content(): array {
        return json_decode(file_get_contents(resource_path('data/employer-resources65.json')), true, 512, JSON_THROW_ON_ERROR);
    }
    private function identity(Request $request): array {
        $resolver = config('employer_resources65.organisation_resolver');
        abort_unless(is_callable($resolver), 503, 'Employer resource access needs configuration.');
        $organisation = $resolver($request->user());
        abort_unless(is_scalar($organisation) && strlen((string)$organisation)>0 && strlen((string)$organisation)<=64, 403);
        return ['user_key'=>(string)$request->user()->getAuthIdentifier(), 'organisation_key'=>(string)$organisation];
    }
    private function query(array $identity, string $version) {
        return DB::table('employer_resource_progress65')->where($identity)->where('content_version', $version);
    }
    public function index(Request $request) {
        $this->identity($request);
        return response()->view('employer-resources65.index', ['resourceContent'=>$this->content(), 'resourceConfig'=>[
            'stateUrl'=>route('employer-resources.state'), 'saveUrl'=>route('employer-resources.save'),
            'csrf'=>csrf_token(), 'downloadBase'=>asset('resources65').'/',
            'safeguardingUrl'=>config('employer_resources65.safeguarding_url'),
            'supportUrl'=>config('employer_resources65.support_url'),
        ]])->header('Cache-Control','private, no-store');
    }
    public function state(Request $request) {
        $identity=$this->identity($request); $content=$this->content(); $result=[];
        foreach ($this->query($identity,$content['version'])->get() as $row) {
            $result[$row->course.':'.$row->page]=['at'=>\Illuminate\Support\Carbon::parse($row->completed_at, 'UTC')->toIso8601String()];
        }
        return response()->json((object)$result)->header('Cache-Control','private, no-store');
    }
    public function save(Request $request) {
        $identity=$this->identity($request); $content=$this->content();
        $input=$request->validate(['course'=>'required|string','page'=>'required|string','version'=>'required|string',
            'answers'=>'present|array|max:20','answers.*'=>'integer|min:0|max:20', 'read'=>'required|boolean',
            'commitment'=>'required|boolean','declarations'=>'present|array|max:30','declarations.*'=>'boolean']);
        abort_unless($input['version']===$content['version'],409,'Content updated. Refresh resources.');
        $course=collect($content['courses'])->firstWhere('id',$input['course']); abort_unless($course,404);
        $page=collect($course['pages'])->firstWhere('id',$input['page']); abort_unless($page,404);
        $fail=fn($message)=>throw ValidationException::withMessages(['progress'=>$message]);
        if(count($input['answers'])!==count($page['questions'])) $fail('Complete every quick check.');
        foreach($page['questions'] as $i=>$question) {
            if(!array_key_exists($i,$input['answers']) || (int)$input['answers'][$i]!==$question['correct']) $fail('Review the quick check answers.');
        }
        if($page['final']) {
            if(!$input['commitment'] || count($input['declarations'])!==count($page['declarations']) || in_array(false,$input['declarations'],false)) $fail('Confirm every commitment.');
            $required=collect($course['pages'])->where('final',false)->pluck('id');
            $saved=$this->query($identity,$content['version'])->where('course',$course['id'])->where('completed_at','>=',now()->utc()->subDays(365))->pluck('page');
            if($required->diff($saved)->isNotEmpty()) $fail('Complete the other topics first.');
        } elseif(!$input['read'] || $input['commitment']) $fail('Confirm you have read this topic.');
        DB::table('employer_resource_progress65')->upsert([
            array_merge($identity,['content_version'=>$content['version'],'course'=>$course['id'],'page'=>$page['id'],
            'score'=>count($page['questions']),'commitment'=>$page['final'],'completed_at'=>now()->utc()])
        ], ['user_key','organisation_key','content_version','course','page'], ['score','commitment','completed_at']);
        return $this->state($request);
    }
}
