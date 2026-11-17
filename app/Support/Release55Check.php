<?php
namespace App\Support;
class Release55Check {
 public static function report(bool$environment=false,bool$database=false):array{$r=Release54Check::report($environment,$database);$r['pack']='55';$r['theme_only']=false;$guides=HelpCatalogue::all();$r['checks']['usability_guide_inventory']=count($guides)===42&&isset($guides['employer-setup'],$guides['journey-progress'],$guides['retention-recruitment']);$r['usability_scope']='Filtered-empty versus unassigned states; explicit directory actions; scoped next steps; referral feedback; accurate save/submit/review guidance and contextual help. Automatic programme-based car/lorry marker follows existing dated milestones with optional reduced-motion-aware animation. Business authorisation and records unchanged.';$r['actual_target_acceptance']=false;$r['writes_performed']=false;if(in_array(false,$r['checks'],true))$r['status']='needs_attention';return$r;}
}
