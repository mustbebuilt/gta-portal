<?php
namespace Tests\Feature;

use App\Models\{Apprentice,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FormErrorRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;
    private function admin(): User {return User::factory()->create(['role'=>'admin']);}
    private function dom(string $html): \DOMXPath
    {
        $dom=new \DOMDocument();$previous=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}return new \DOMXPath($dom);
    }
    private function linkedErrors(\DOMXPath $dom,array $fields): void
    {
        foreach($fields as $field){
            $this->assertSame(1,$dom->query('//section[@data-error-summary]//a[@href="#'.$field.'"]')->length,$field.' summary target');
            $this->assertSame(1,$dom->query('//*[@id="'.$field.'" and @aria-invalid="true" and @aria-describedby="'.$field.'-error"]')->length,$field.' input description');
            $this->assertSame(1,$dom->query('//*[@id="'.$field.'-error"]')->length,$field.' unique error description');
        }
    }
    public function test_rejected_apprentice_creation_links_visible_errors_retains_input_and_creates_no_record(): void
    {
        $this->actingAs($this->admin());$name='Fictional <script>alert("test")</script>';
        $this->from(route('apprentices.create'))->post(route('apprentices.store'),['reference'=>'BAD REFERENCE','first_name'=>$name,'last_name'=>'Learner','start_date'=>'2026-11-13','planned_end_date'=>'2026-11-12','mentor_id'=>999999])->assertRedirect();
        $response=$this->get(route('apprentices.create'))->assertOk();$dom=$this->dom($response->getContent());$this->linkedErrors($dom,['reference','planned_end_date','mentor_id']);
        $this->assertSame($name,$dom->query('//input[@id="first_name"]')->item(0)->getAttribute('value'));$this->assertSame('2026-11-12',$dom->query('//input[@id="planned_end_date"]')->item(0)->getAttribute('value'));$this->assertSame(0,$dom->query('//script[not(@src)]')->length);
        $this->assertStringContainsString('learner reference',$dom->query('//section[@data-error-summary]')->item(0)->textContent);$this->assertStringContainsString('planned training end date',$dom->query('//section[@data-error-summary]')->item(0)->textContent);$this->assertDatabaseCount('apprentices',0);
    }
    public function test_rejected_staff_directory_record_links_input_and_select_errors_without_creating_an_account(): void
    {
        $this->actingAs($this->admin());$this->from(route('directories.create','staff'))->post(route('directories.store','staff'),['name'=>'Fictional Member','email'=>'invalid','active'=>1,'role'=>'functional_skills_tutor','subject_scope'=>''])->assertRedirect();
        $response=$this->get(route('directories.create','staff'))->assertOk();$dom=$this->dom($response->getContent());$this->linkedErrors($dom,['email','subject_scope']);$this->assertSame('Fictional Member',$dom->query('//input[@id="name"]')->item(0)->getAttribute('value'));$this->assertSame(1,$dom->query('//select[@id="role"]/option[@value="functional_skills_tutor" and @selected]')->length);$this->assertDatabaseCount('users',1);$this->assertDatabaseCount('directory_events',0);
    }
    public function test_stale_apprentice_save_is_plain_summary_error_and_does_not_link_to_hidden_version_or_change_record(): void
    {
        $a=Apprentice::factory()->create(['version'=>3]);$before=$a->fresh()->getAttributes();$this->actingAs($this->admin());
        $this->from(route('apprentices.edit',$a))->put(route('apprentices.update',$a),['reference'=>$a->reference,'first_name'=>'Unsaved change','last_name'=>$a->last_name,'version'=>2])->assertRedirect();
        $response=$this->get(route('apprentices.edit',$a))->assertOk();$dom=$this->dom($response->getContent());$this->assertSame(1,$dom->query('//section[@data-error-summary]')->length);$this->assertSame(0,$dom->query('//section[@data-error-summary]//a')->length);$this->assertSame($before,$a->fresh()->getAttributes());
    }
    public function test_clean_forms_do_not_render_error_summary_and_directory_kinds_keep_unique_visible_targets(): void
    {
        $this->actingAs($this->admin());
        foreach(['employers','staff','groups','programmes'] as $kind){$response=$this->get(route('directories.create',$kind))->assertOk();$dom=$this->dom($response->getContent());$this->assertSame(0,$dom->query('//section[@data-error-summary]')->length);$ids=[];foreach($dom->query('//*[@id]') as $node){$ids[]=$node->getAttribute('id');}$this->assertSame(count($ids),count(array_unique($ids)));}
        $this->get(route('apprentices.create'))->assertOk()->assertDontSee('data-error-summary',false);
    }
}
