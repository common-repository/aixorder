<?php
/*
Plugin Name: aiXorder
Plugin URI: http://www.aixo.fr/aixorder/
Description: Reorder your posts depending on their novelty and popularity.
Version: 1.1
Author: Ek0
Author URI: http://www.aixo.fr

    Copyright 2008  Ek0  (email : Ek0@aixo.fr)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*
Todo list
	- Ajax admin interface
	- Settings overview
	- Do not track admins views
*/

/******************************************************************************
* aixorder class
*******************************************************************************/
class aixorder{

	var $logged;
	var $version = "1.1";
		
	/******************************************************************************
	* Constructor
	*******************************************************************************/
	function aixorder(){
		/* Activation */
		register_activation_hook(__FILE__, array(&$this, 'aixorder_activate'));
		add_action('deactivate_'.plugin_basename(__FILE__), array(&$this, 'aixorder_deactivate'));
		
		/* Posts customized display order */
		add_filter('posts_orderby', array(&$this, 'reorder_listings'));
		
		/* Scores count */
		add_action('the_content', array(&$this, 'record_view'));
		add_action('publish_post', array(&$this, 'publish_post'));

		/* Database maintenance */
		add_action('delete_post', array(&$this, 'post_delete'));		
		add_action('recalculate_all_scores_hook', array(&$this, 'recalculate_all_scores') );
		
		/* Shortcodes */
		add_shortcode('aixorder-chart', array(&$this, 'top_chart'));
		
		/* Admin menu */
		add_action('admin_menu', array(&$this, 'admin_menu'));
		
		/* Translation */
		add_action('init', array(&$this, 'aixorder_translate'));
		
		return true;
	}
	
	/******************************************************************************
	* Installation, activation
	*******************************************************************************/
	
	/* Plugin installation */
	function aixorder_activate() {
		global $wpdb;
		
		/* Variables initialisation */
		$this->logged = 0;
		
		/* Database modification */
		require_once(ABSPATH.'wp-admin/install-helper.php');
		
		maybe_add_column($wpdb->prefix."posts", 'aixorder_score', "ALTER TABLE ".$wpdb->prefix."posts ADD aixorder_score INT(11) NOT NULL DEFAULT 0;");
				
		$result = mysql_list_tables(DB_NAME);
		$tables = array();
		while ($row = mysql_fetch_row($result)) {
			$tables[] = $row[0];
		}
		if (!in_array($wpdb->prefix.'aixorder', $tables)) {
			$result = $wpdb->query("
				CREATE TABLE ".$wpdb->prefix."aixorder(
					post_id INT(11) NOT NULL
					, novelty INT(11) NOT NULL
					, success INT(11) NOT NULL
					, sticky INT( 11) NOT NULL
					, feed_views INT(11) NOT NULL
					, home_views INT(11) NOT NULL
					, archive_views INT(11) NOT NULL
					, category_views INT(11) NOT NULL
					, single_views INT(11) NOT NULL
					, comments INT(11) NOT NULL
					, pingbacks INT(11) NOT NULL
					, trackbacks INT(11) NOT NULL
					, KEY post_id (post_id)
				);");
		}

		/* Reset to default settings */
		$this->reset_settings();
		
		/* First calcul of scores */
		$result = $this->recalculate_all_scores();
		
		/* Schedule score calcul */
		if (!wp_next_scheduled('recalculate_all_scores_hook')) {
			wp_schedule_event(time(), 'hourly', 'recalculate_all_scores_hook' );
		}
		
		return $result;
	}
	
	/* Reset to default settings */
	function reset_settings(){
		update_option('aixorder_popularity_max','100');
		update_option('aixorder_trackback_value', '100');
		update_option('aixorder_pingback_value', '80');
		update_option('aixorder_comment_value','50');
		update_option('aixorder_single_value','10');
		update_option('aixorder_archive_value','4');
		update_option('aixorder_category_value','5');
		update_option('aixorder_feed_value','1');
		update_option('aixorder_home_value','2');
		update_option('aixorder_novelty_period','30');
		update_option('aixorder_novelty_coeff','50');
		update_option('aixorder_period_sticky','5');
		update_option('aixorder_nb_sticky_posts','2');
		update_option('aixorder_wp-postratings','0');
		update_option('aixorder_order_home','on');
		update_option('aixorder_order_category','on');
		update_option('aixorder_order_feed','on');
		update_option('aixorder_order_search','on');
		update_option('aixorder_order_archive','on');
	}
	
	/******************************************************************************
	* Maintenance
	*******************************************************************************/
	
	/* Plugin uninstallation */
	function aixorder_deactivate(){
		global $wpdb;
		
		/* Drop table aixorder :'( */
		$result = $wpdb->query("DROP TABLE ".$wpdb->prefix."aixorder");
		$result = $wpdb->query("ALTER TABLE ".$wpdb->prefix."posts DROP COLUMN aixorder_score");		
		return true;
	}

	/* Stats reset */
	function reset_stats(){
		global $wpdb;
		
		/* Drop data */
		$result = $wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."aixorder");
		$result = $wpdb->query("UPDATE TABLE ".$wpdb->prefix."posts SET aixorder_score = NULL");		
		return true;
	}
	
	/* Post delete */
	function post_delete($post_ID){
		global $wpdb;
		
		/* Drop data */
		$result = $wpdb->query("DELETE FROM ".$wpdb->prefix."posts WHERE post_id = '$post_ID'");		
		return true;
	}
	
	/******************************************************************************
	* Scores count
	*******************************************************************************/
	
	/* Calculates all scores and writes it in the database. */
	function recalculate_all_scores(){
		//echo "calculate_all_scores<br/>";
		global $wpdb;
		$success = 0;
		
		/* Calculate score parameters for each post*/
		$posts = mysql_query("SELECT ID FROM ".$wpdb->prefix."posts WHERE post_status='publish'");
		if ($posts && mysql_num_rows($posts) > 0) {
			while ($post = mysql_fetch_object($posts)) {
				$this->create_post_record($post->ID);
				$this->count_post_comments($post->ID);
				$this->calculate_post_novelty($post->ID);
				$this->calculate_post_sticky($post->ID);
				$success_tmp = $this->calculate_post_success($post->ID);
				/* Adjust maximum succes score if needed */
				if($success_tmp > $success){
					$success = $success_tmp;
				}
			}
		}
		
		
		/* Adjust maximum succes score if needed */
		if($success > get_option('aixorder_popularity_max')){
			update_option('aixorder_popularity_max',$success);
		}
		
		/* Calculate score for each post*/
		$posts = mysql_query("SELECT ID FROM ".$wpdb->prefix."posts WHERE post_status='publish'");
		if ($posts && mysql_num_rows($posts) > 0) {
			while ($post = mysql_fetch_object($posts)) {
				$this->calculate_post_success($post->ID);
				$this->calculate_post_score($post->ID);
			}
		}
		
		/* Now maximum success score is back to 100. */
		update_option('aixorder_popularity_max','100');

		return true;
	}

	/* Initialisation of a post in the table if needed*/
	function create_post_record($post_ID) {
		//echo "create_post_record<br/>";
		global $wpdb;
		
		$query = "SELECT post_id FROM ".$wpdb->prefix."aixorder WHERE post_id = '$post_ID'";
		$result = mysql_query($query);
		
		if (mysql_num_rows($result) == 0) {
			$result = $wpdb->query("
				INSERT 
				INTO ".$wpdb->prefix."aixorder
				VALUES
				(
					'$post_ID'
					, '0'
					, '0'
					, '0'
					, '0'
					, '0'
					, '0'
					, '0'
					, '0'
					, '0'
					, '0'
					, '0'
				)
			");
		}
	}

	/* Inserts number of comments, pings and trackbacks into table*/
	function count_post_comments($post_id) {
		//echo "populate_post_comments<br/>";
		global $wpdb;
		
		/* Count existing comments */
		$result = mysql_query("
			SELECT comment_ID
			FROM $wpdb->comments
			WHERE comment_post_ID = '$post_id'
			AND comment_type = ''
			AND comment_approved = '1'
		");
		$nb_comments = mysql_num_rows($result);

		/* Count existing trackbacks */
		$result = mysql_query("
			SELECT comment_ID
			FROM $wpdb->comments
			WHERE comment_post_ID = '$post_id'
			AND comment_type = 'trackback'
			AND comment_approved = '1'
		");
		$nb_trackbacks = mysql_num_rows($result);

		/* Count existing pingbacks */
		$result = mysql_query("
			SELECT comment_ID
			FROM ".$wpdb->prefix."comments
			WHERE comment_post_ID = '$post_id'
			AND comment_type = 'pingback'
			AND comment_approved = '1'
		");
		
		$nb_pingbacks = mysql_num_rows($result);

		/* Write values in the table */
		if ($nb_comments > 0 || $nb_trackbacks > 0 || $nb_pingbacks > 0 ) {

			$result = mysql_query("
				UPDATE ".$wpdb->prefix."aixorder
				SET comments = $nb_comments, trackbacks = $nb_trackbacks, pingbacks = $nb_pingbacks
				WHERE post_id = '$post_id'
			");

			if (!$result) {
				return false;
			}
		}
	}
	
	/* Inserts post novelty value into table*/
	function calculate_post_novelty($post_id) {
		//echo "populate_post_novelty<br/>";
		global $wpdb;
		
		/* Retrieving days passed since the post was published*/
		$result = mysql_query("SELECT DATEDIFF(NOW(),post_date) FROM ".$wpdb->prefix."posts WHERE ID = '$post_id'");
		if (!$result) {
			return false;
		}
		$date_diff = mysql_fetch_array($result);
		
		/* Calculting novelty points */
		$novelty = 0;
		$novelty_period = get_option('aixorder_novelty_period');
		if($date_diff[0] <= $novelty_period){
			$novelty = (int)floor((float)((1-($date_diff[0]/$novelty_period))*100));
		}
		
		/* Write value into table */
		$result = mysql_query("UPDATE ".$wpdb->prefix."aixorder SET novelty = $novelty WHERE post_id = '$post_id'");
		if (!$result) {
			return false;
		}
	}
	
	/* Inserts post sticky value into table*/
	function calculate_post_sticky($post_id) {
		// echo "calculate_post_sticky<br/>";
		global $wpdb;
		$sticky = 0;
		
		/* Retrieving days passed since the post was published*/
		$result = mysql_query("SELECT DATEDIFF(NOW(),post_date) FROM ".$wpdb->prefix."posts WHERE ID = '$post_id'");
		if (!$result) {
			return false;
		}
		$date_diff = mysql_fetch_array($result);
		
		/* Determining if sticky or not */
		$sticky_period = get_option('aixorder_period_sticky');
		if($date_diff[0] <= $sticky_period){
			$sticky =1;
		}
		
		$posts = mysql_query("SELECT ID FROM ".$wpdb->prefix."posts WHERE post_status='publish' AND post_type='post' ORDER BY post_date DESC LIMIT 0,".get_option('aixorder_nb_sticky_posts'));
		
		if ($posts && mysql_num_rows($posts) > 0) {
			while ($post = mysql_fetch_object($posts)) {
				if($post->ID == $post_id){
					$sticky = 1;
				}
			}
		}
		
		/* Write value into table */
		$result = mysql_query("UPDATE ".$wpdb->prefix."aixorder SET sticky = $sticky WHERE post_id = '$post_id'");
		if (!$result) {
			return false;
		}
		return true;
	}
	
	/* Inserts post success value into table aixorder and posts*/
	function calculate_post_success($post_id) {
		//echo "calculate_post_score<br/>";
		global $wpdb;
		$success = 0;
		
		/* Retrieving data of post*/
		$result = mysql_query("SELECT feed_views as feed_views, archive_views as archive_views, home_views as home_views, category_views as category_views, single_views as single_views, comments as comments, pingbacks as pingbacks, trackbacks as trackbacks FROM ".$wpdb->prefix."aixorder WHERE post_id = '$post_id'");
		$data = mysql_fetch_array($result);

		/* Retrieving rating of post if WP-PostsRatings installed*/
		$rating = 0;
		if(function_exists('the_ratings')) {		
			$result = mysql_query("SELECT p.meta_value as rating FROM ".$wpdb->prefix."aixorder a, ".$wpdb->prefix."postmeta p WHERE p.post_id = '$post_id' AND p.meta_key = 'ratings_average'");
			$average_rating = mysql_fetch_array($result);
			if($average_rating['rating']){
				$rating = $average_rating['rating'];
			}
		}
		
		/* Popularity calcul */
		$success = floor(((get_option('aixorder_single_value') * $data['single']
						+ get_option('aixorder_feed_value') * $data['feed']
						+ get_option('aixorder_category_value') * $data['category']
						+ get_option('aixorder_archive_value') * $data['archive']
						+ get_option('aixorder_comment_value') * $data['comments']
						+ get_option('aixorder_pingback_value') * $data['pingbacks']
						+ get_option('aixorder_trackback_value') * $data['trackbacks']
						+ get_option('aixorder_wp-postratings') * $rating
						)/get_option('aixorder_popularity_max'))*100
						);
						
		/* Write value into table posts */
		$result = $wpdb->query("UPDATE ".$wpdb->prefix."aixorder SET success = $success WHERE post_id = '$post_id'");
		
		return $success;
	}

		
	/* Inserts post score value into table aixorder and posts*/
	function calculate_post_score($post_id) {
		//echo "calculate_post_score<br/>";
		global $wpdb;
		$score = 0;
	
		/* Retrieving data of post*/
		$result = mysql_query("SELECT novelty as novelty, sticky as sticky, success as success FROM ".$wpdb->prefix."aixorder WHERE post_id = '$post_id'");
		//if (!$result) {
		//	return false;
		//}
		$data = mysql_fetch_array($result);
		
		//echo get_option('aixorder_popularity_max')."<br/>";
		
		if($data['sticky'] == 1){
			$score = 1000000000;
		}else{
			$score = floor(
							(get_option('aixorder_novelty_coeff')/100)* $data['novelty']
							+ ((1 - (get_option('aixorder_novelty_coeff')/100)) * $data['success'])
							);
		}
		
		/* Write value into table posts */
		$result = $wpdb->query("UPDATE ".$wpdb->prefix."posts SET aixorder_score = $score WHERE ID = '$post_id'");
		//if (!$result) {
		//	return false;
		//}
		
		return true;
	}
	
	
	
	/******************************************************************************
	* Scores updates
	*******************************************************************************/
	
	/* Update score on post view */
	function record_view($content) {
		
		if ($this->logged > 0) {
			return $content;
		}
		global $wpdb, $posts;
		
		if (!isset($posts) || !is_array($posts) || count($posts) == 0) /* || is_admin_page())*/ {
			return;
		}
		
		$ids = array();
		$aixorder_posts = $posts;
		foreach ($aixorder_posts as $post) {
			$ids[] = $post->ID;
		}
		
		if (is_feed()) {
			$result = mysql_query("UPDATE ".$wpdb->prefix."aixorder SET feed_views = feed_views + 1 WHERE post_id IN (".implode(',', $ids).")");

		}
		else if (is_archive() && !is_category()) {
			$result = mysql_query("UPDATE ".$wpdb->prefix."aixorder SET archive_views = archive_views + 1 WHERE post_id IN (".implode(',', $ids).")");

		}
		else if (is_category()) {
			$result = mysql_query("UPDATE ".$wpdb->prefix."aixorder SET category_views = category_views + 1 WHERE post_id IN (".implode(',', $ids).")");

		}
		else if (is_single()) {
			$result = mysql_query("UPDATE ".$wpdb->prefix."aixorder SET single_views = single_views + 1 WHERE post_id = '".$ids[0]."'");

		}
		else {
			$result = mysql_query("UPDATE ".$wpdb->prefix."aixorder SET home_views = home_views + 1 WHERE post_id IN (".implode(',', $ids).")");
		}
		
		$this->logged++;
		
		return $content;
	}

	/* Initialize score on post publication */
	function publish_post($post_ID) {
		$result = $this->recalculate_all_scores();
		return $result;
	}
	
	
	
	/******************************************************************************
	* Posts customized display order 
	*******************************************************************************/
	
	/* Order posts by score */
	function reorder_listings($orderby){
		global $wpdb;
		if(is_home() && get_option('aixorder_order_home') == 'on'
		|| is_category() && get_option('aixorder_order_category') == 'on'
		|| is_feed() && get_option('aixorder_order_feed') == 'on'
		|| is_search() && get_option('aixorder_order_search') == 'on'
		|| is_archive() && get_option('aixorder_order_archive') == 'on'
		){
			return $wpdb->prefix."posts.aixorder_score DESC, ".$wpdb->prefix."posts.post_date DESC";
		}else{
			return $orderby;
		}
	}

	
	/******************************************************************************
	* Shortcode inclusions
	*******************************************************************************/
	function top_chart($atts = null, $content = null){
		return '<br/><img src="'.$this->setup_chart().'"><br/>';
	}
	
	
	/******************************************************************************
	* Admin menus
	*******************************************************************************/
	function admin_menu(){
		add_options_page('aiXorder', 'aiXorder', 8, __FILE__, array(&$this, 'display_admin_menu'));
	}

	function display_admin_menu(){
		global $wpdb;
		?>
		
		<div class="wrap">
			<script type="text/javascript">
			<!--
				function toggleVisibility(id) {
				   var e = document.getElementById(id);
				   if(e.style.display == 'block')
					  e.style.display = 'none';
				   else
					  e.style.display = 'block';
				}
			//-->
			</script>
		
			<h2>aiXorder</h2>
			<p><em><?php _e('Reorder your posts depending on their novelty and popularity','aixorder'); ?></em> | version <?php echo $this->version; ?> | <a title="<?php _e('Official Plugin Page','aixorder'); ?>" href="http://www.aixo.fr/aixorder"><?php _e('Official plugin page','aixorder'); ?></a> | <?php _e('written by','aixorder'); ?> <a title="aiXo.fr" href="http://www.aixo.fr">Ek0</a><br/>
			
			<?php
				/* Actions to do */
				if (!empty($_POST['aixorder_uninstall'])) {

					$this->aixorder_deactivate();
					$plugin = plugin_basename(__FILE__);
					deactivate_plugins($plugin);
			?>
					</p><br/><br/>
					<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_uninstalled" id="aixorder_uninstalled">
						<legend style="color: #2583ad; font-weight:bold;"><?php _e('aiXorder was uninstalled','aixorder'); ?></legend>
						<p>
							<?php _e('The plugin was uninstalled without any problem. Your database was cleaned from aiXorder data. You can reinstall it simply by activating it in the plugins list, but your visits statistics will start from zero again.','aixorder'); ?>
						</p>
					</fieldset>
					
			<?php
				}else{
				if(!empty($_POST['aixorder_reset_settings'])){
					$this->reset_settings();
				}
				if(!empty($_POST['aixorder_reset'])){
					$this->reset_stats();
				}
				
				/* Display page */
				?>
			<?php _e('You can <a href="#aixorder_graph">view your posts points</a>, <a href="#aixorder_settings">update the plugin settings</a> or <a href="#aixorder_help">find help here.</a>','aixorder'); ?></p>
			<br/>
			<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_graph" id="aixorder_graph">
				<legend style="color: #2583ad; font-weight:bold;"><?php _e('Posts points with current settings','aixorder'); ?></legend>
					<br/><img src="<?php echo $this->setup_chart(); ?>">
			</fieldset>
			<br/><br/>
			<h2 id="aixorder_settings"><?php _e('Plugin settings','aixorder'); ?></h2>
			<br/>
			<form method="post" action="options.php">
				<?php wp_nonce_field('update-options'); ?>

				<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_sections">
					<legend style="color: #2583ad; font-weight:bold;"><?php _e('Custom order sections','aixorder'); ?></legend>
					<table class="form-table">
						<tbody>
						
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Display the posts ordered by aiXorder score on...','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_sections_tip');">[?]</a>
								</th>
								<td>
								<input type="checkbox" name="aixorder_order_home" id="aixorder_order_home" <?php echo (get_option('aixorder_order_home')=='on')?"checked":""; ?> /> <label for="aixorder_order_home"><?php _e('Home','aixorder'); ?></label><br/>
								<input type="checkbox" name="aixorder_order_feed" id="aixorder_order_feed" <?php echo (get_option('aixorder_order_feed')=='on')?"checked":""; ?> /> <label for="aixorder_order_feed"><?php _e('Feed','aixorder'); ?></label><br/>
								<input type="checkbox" name="aixorder_order_category" id="aixorder_order_category" <?php echo (get_option('aixorder_order_category')=='on')?"checked":""; ?> /> <label for="aixorder_order_category"><?php _e('Categories','aixorder'); ?></label><br/>
								<input type="checkbox" name="aixorder_order_archive" id="aixorder_order_archive" <?php echo (get_option('aixorder_order_archive')=='on')?"checked":""; ?> /> <label for="aixorder_order_archive"><?php _e('Archives','aixorder'); ?></label><br/>
								<input type="checkbox" name="aixorder_order_search" id="aixorder_order_search" <?php echo (get_option('aixorder_order_search')=='on')?"checked":""; ?> /> <label for="aixorder_order_search"><?php _e('Search results','aixorder'); ?></label><br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_sections_tip">
									<?php _e('Choose which section you want to reorder using the custom aiXorder score. If you do not want to surprise your daily visitors but show a more adapted listing in categories and archives, you could deactivate "Home". In deactivated sections, the listings are ordered by date.','aixorder'); ?>
									</div>
								</td>
							</tr>

						</tbody>
					</table>
				</fieldset>

				<br/>		
				<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_sticky_posts">
					<legend style="color: #2583ad; font-weight:bold;"><?php _e('Sticky posts','aixorder'); ?></legend>
					<table class="form-table">
						<tbody>
						
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Force the X last posts on first position','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_nb_sticky_posts_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_nb_sticky_posts" id="aixorder_nb_sticky_posts" value="<?php echo get_option('aixorder_nb_sticky_posts'); ?>"/><br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_nb_sticky_posts_tip">
									<?php _e('Force your X last posts to be shown on first position when viewing your home page. Can be used in parallel of the next feature. For example, if you want to modify the order to start only on page 2, enter the number of posts you display on your home page.','aixorder'); ?>
									</div>
								</td>
							</tr>
							
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
								<?php _e('Force posts younger than Y days on first position.','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_period_sticky_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_period_sticky" id="aixorder_period_sticky" value="<?php echo get_option('aixorder_period_sticky'); ?>"/><br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_period_sticky_tip">
									<?php _e('Force the posts younger than Y days to be shown on first position when viewing your home page. Can be used in parallel of the precedent feature. For example, if you want to keep a normal order for posts younger than a month, put 30. If you just want to give bonus points to young posts, check "Novelty points calcul" section.','aixorder'); ?>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</fieldset>

				<br/>
				<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_focus_on">
					<legend style="color: #2583ad; font-weight:bold;"><?php _e('Focus on...','aixorder'); ?></legend>
					<table class="form-table">
						<tbody>
						
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Focus on...','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_novelty_coeff_tip');">[?]</a>
								</th>
								<td>
									<strong><?php _e('Popularity','aixorder'); ?></strong>    <input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="0" <?php echo (get_option('aixorder_novelty_coeff')==0)?"checked":""; ?> />0%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="10" <?php echo (get_option('aixorder_novelty_coeff')==10)?"checked":""; ?> />10%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="20" <?php echo (get_option('aixorder_novelty_coeff')==20)?"checked":""; ?> />20%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="30" <?php echo (get_option('aixorder_novelty_coeff')==30)?"checked":""; ?> />30%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="40" <?php echo (get_option('aixorder_novelty_coeff')==40)?"checked":""; ?> />40%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="50" <?php echo (get_option('aixorder_novelty_coeff')==50)?"checked":""; ?> />50%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="60" <?php echo (get_option('aixorder_novelty_coeff')==60)?"checked":""; ?> />60%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="70" <?php echo (get_option('aixorder_novelty_coeff')==70)?"checked":""; ?> />70%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="80" <?php echo (get_option('aixorder_novelty_coeff')==80)?"checked":""; ?> />80%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="90" <?php echo (get_option('aixorder_novelty_coeff')==90)?"checked":""; ?> />90%
									<input type="radio" name="aixorder_novelty_coeff" id="aixorder_novelty_coeff" value="100" <?php echo (get_option('aixorder_novelty_coeff')==100)?"checked":""; ?> />100%    <strong><?php _e('Novelty','aixorder'); ?></strong>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_novelty_coeff_tip">
									<?php _e('Choose here the balance between popularity and novelty in the score calcul. If you choose 100%, the score will only be based on novelty, so the the order will be the same as if there was no plugin. If you choose 0%, the order will not take in consideration the post date at all. A good value is 50%.','aixorder'); ?>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</fieldset>
				
				<br/>
				<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_novelty_calcul">
					<legend style="color: #2583ad; font-weight:bold;"><?php _e('Novelty calcul settings','aixorder'); ?></legend>
					<table class="form-table">
						<tbody>
						
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Give novelty points to posts youger than X days','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_novelty_period_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_novelty_period" id="aixorder_novelty_period" value="<?php echo get_option('aixorder_novelty_period'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_novelty_period_tip">
									<?php _e('Choose here how many times a new published post will get novelty points. When you publish it, its novelty score is 100%, and it is each day decremented. After X days, it reaches zero. Define well this period if you do not want to see recent posts doubled by particularly popular posts.','aixorder'); ?>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</fieldset>

				<br/>
				<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_popularity_calcul">
					<legend style="color: #2583ad; font-weight:bold;"><?php _e('Popularity calcul settings','aixorder'); ?></legend>
					<table class="form-table">
						<tbody>
						
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Home view value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_home_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_home_value" id="aixorder_home_value" value="<?php echo get_option('aixorder_home_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_home_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>
							
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Single view value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_single_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_single_value" id="aixorder_single_value" value="<?php echo get_option('aixorder_single_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_single_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>

							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Category view value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_category_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_category_value" id="aixorder_category_value" value="<?php echo get_option('aixorder_category_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_category_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Archive view value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_archive_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_archive_value" id="aixorder_archive_value" value="<?php echo get_option('aixorder_archive_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_archive_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Feed view value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_feed_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_feed_value" id="aixorder_feed_value" value="<?php echo get_option('aixorder_feed_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_feed_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Comment value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_comment_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_comment_value" id="aixorder_comment_value" value="<?php echo get_option('aixorder_comment_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_comment_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Pingback value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_pingback_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_pingback_value" id="aixorder_pingback_value" value="<?php echo get_option('aixorder_pingback_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_pingback_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Trackback value','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_trackback_value_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_trackback_value" id="aixorder_trackback_value" value="<?php echo get_option('aixorder_trackback_value'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_trackback_value_tip">
										<?php _e('Choose here how many popularity points a post will get for this type of view. Default values are trustable.','aixorder'); ?>
									</div>
								</td>
							</tr>
							
						</tbody>
					</table>
				</fieldset>
				
				<br/>
				<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_wp-postratings_integration">
					<legend style="color: #2583ad; font-weight:bold;"><?php _e('WP-PostRatings integration','aixorder'); ?></legend>
					<table class="form-table">
						<tbody>

							<tr>
								<?php if(function_exists('the_ratings')) {?>  
								<th scope="row" style="text-align: right; vertical-align: top;">
									<?php _e('Coefficient of WP-PostRatings scores in the popularity calcul','aixorder'); ?> <a style="cursor: pointer;" title="<?php _e('Click for help!','aixorder'); ?>" onclick="toggleVisibility('aixorder_wp-postratings_tip');">[?]</a>
								</th>
								<td>
									<input type="text" size="3" name="aixorder_wp-postratings" id="aixorder_wp-postratings" value="<?php echo get_option('aixorder_wp-postratings'); ?>"/>
									<br/>
									<div style="max-width: 500px; text-align: left; display: none;" id="aixorder_wp-postratings_tip">
									<?php _e('Choose here the coefficient to apply to WP-PostsRatings scores when calculating popularity score. The average score will be used. For example, if the post average rating is 4.7, 4.7*X points will be added to the popularity score. Just put 0 if you do not want WP-PostsRatings to be part of the aiXorder score.','aixorder'); ?>
									</div>
								</td>
								<?php }else{ ?>
									<td>
										<?php _e('<strong>WP-PostsRatings does not seem to be installed and activated on this blog. </strong>WP-PostsRating is a very good Wordpress plugin that allows visitors to rate your posts. aiXorder can integrate this rating in the popularity score calcul ! Find more information about this plugin on <a href="http://wordpress.org/extend/plugins/wp-postratings/">the official plugin repository</a>.','aixorder'); ?>
									</td>
								<?php } ?>
							</tr>
						</tbody>
					</table>
				</fieldset>
				
				<input type="hidden" name="action" value="update" />
				<input type="hidden" name="page_options" value="aixorder_trackback_value,aixorder_pingback_value,aixorder_comment_value,aixorder_single_value,aixorder_home_value,aixorder_archive_value,aixorder_category_value,aixorder_feed_value,aixorder_novelty_period,aixorder_novelty_coeff,aixorder_period_sticky,aixorder_nb_sticky_posts,aixorder_wp-postratings,aixorder_order_home,aixorder_order_category,aixorder_order_feed,aixorder_order_search,aixorder_order_archive" />	
					
				<p class="submit">
					<input type="submit" name="Submit" value="<?php _e('Save settings','aixorder'); ?>" />
					<small><?php _e('(Changes will immediatly be shown on the graph.)','aixorder'); ?></small>
				</p>
			</form>
			<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=aixorder%2Faixorder.php">
				<p class="submit">
					<input type="submit" id="aixorder_reset_settings" name="aixorder_reset_settings" value="<?php _e('Reset settings','aixorder'); ?>" />
				</p>
			</form>
				
			<br/><br/>
			<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_help" id="aixorder_help">
				<legend style="color: #2583ad; font-weight:bold;"><?php _e('Help | Instructions','aixorder'); ?></legend>
				<p><strong><?php _e('How are posts ordered ?','aixorder'); ?></strong><br/><?php _e('aiXorder relies on a system of points. For each post, there are two main counters: novelty et popularity. You can fully customize the calcul of the points in this page. To understand how points are calculated exactly, here are the rules used.','aixorder'); ?></p><br/>
				<p align="center"><img src="<?php echo '../wp-content/plugins/'.dirname(plugin_basename(__FILE__)).'/img/'.__('rules_EN.png','aixorder'); ?>" /></p>
				<p><?php _e('Do not hesitate to click on the "[?]" for each parameter to get precisions.','aixorder'); ?></p>
				<p><br/><strong><?php _e('What are the good settings ?','aixorder'); ?></strong><br/><?php _e('It is up to you to decide what you want to show to your visitors ! It depends on the type of your blog... For example, if you write often, you could have to define a bigger number of sticky posts, otherwise your last articles will go down before having a enough big score to stay on first page.','aixorder'); ?></p>
				<p><br/><strong><?php _e('When are scores updated ?','aixorder'); ?></strong><br/><?php _e('In order not to overload the Wordpress database, scores are not updated at each action or each view. But they are updated often enough to always be representative: each hour, when a new post is published, and each time you open this page.','aixorder'); ?></p>
				<p><br/><strong><?php _e('I want to show the chart on a page, how do I do ?','aixorder'); ?></strong><br/><?php _e('If you want to insert the chart you can see on this settings page, you can do it easily: just put the following shortcode anywhere you want in the editor when writing a post or a page:','aixorder'); ?><p style="border-left: 5px solid rgb(195, 215, 234); padding: 5px 20px 5px 10px; background: rgb(240, 240, 240) url(http://cs.aixo.fr/wp-content/plugins/NiceWeb2CSS/icon/noicon.gif) no-repeat scroll 10px 5px; font-family: Courier New,Courier,mono,times new roman; line-height: 150%; color: rgb(102, 102, 102);">[aixorder-chart]</p><?php _e('You can also insert it directly in a theme template, adding the following line:','aixorder'); ?><p style="border-left: 5px solid rgb(195, 215, 234); padding: 5px 20px 5px 10px; background: rgb(240, 240, 240) url(http://cs.aixo.fr/wp-content/plugins/NiceWeb2CSS/icon/noicon.gif) no-repeat scroll 10px 5px; font-family: Courier New,Courier,mono,times new roman; line-height: 150%; color: rgb(102, 102, 102);"><?php echo '< ?php if(function_exists(\'aixorder_top_chart\')){aixorder_top_chart();} ?>'; ?></p><?php _e('<br/>In the next update, you will be able to customize this chart ;)','aixorder'); ?></p>
				<p><br/><strong><?php _e('Where do I find help ?','aixorder'); ?></strong><br/><?php _e('You will find help on the <a title="Official Plugin Page" href="http://www.aixo.fr/aixorder">official plugin page</a>.','aixorder'); ?></p>
			</fieldset>
			
			<br/><br/>
			<fieldset style="padding:15px;border:1px solid #c6d9fd" name="aixorder_uninst" id="aixorder_uninst">
				<legend style="color: #2583ad; font-weight:bold;"><?php _e('Database maintenance','aixorder'); ?></legend>
				<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=aixorder%2Faixorder.php&updated=true">
					<?php _e('aiXorder uses a custom table in your wordpress database to store statistics. If you just deactivate the plugin on the plugins list, this table will not be dropped in order to keep your statistics. If you want to uninstall aiXorder properly, click on "Uninstall aiXorder" and all data will be erased.','aixorder'); ?>
					<p class="submit">
						<input type="submit" id="aixorder_uninstall" name="aixorder_uninstall" value="<?php _e('Uninstall aiXorder','aixorder'); ?> &raquo;" onclick="return confirm('<?php _e('Are you sure you want to do this ?','aixorder'); ?>')" />
					</p>
				</form>
				<br/><br/>
				<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=aixorder%2Faixorder.php&updated=true">
					<?php _e('You can also only reset the posts statistics since the plugin installation. All statistics will be lost, beware: views data cannot be recalculated.','aixorder'); ?>
					<p class="submit">
						<input type="submit" id="aixorder_reset" name="aixorder_reset" value="<?php _e('Reset all stats','aixorder'); ?> &raquo;" />
					</p>
				</form>
			</fieldset>
			
			
		</div>
		
	<?php }
	}
	
	/* Returns the URL of the generated Google chart.*/
	function setup_chart(){
		global $wpdb;
		
		/* First recalculate scores */
		$this->recalculate_all_scores();
		
		/* Retrieving data for the graphic */
		$query = "SELECT ".$wpdb->prefix."posts.post_title as title, ".$wpdb->prefix."aixorder.success as success, ".$wpdb->prefix."aixorder.sticky as sticky, ".$wpdb->prefix."aixorder.novelty as novelty FROM ".$wpdb->prefix."aixorder INNER JOIN ".$wpdb->prefix."posts ON ".$wpdb->prefix."aixorder.post_id = ".$wpdb->prefix."posts.ID WHERE ".$wpdb->prefix."posts.post_type='post' ORDER BY ".$wpdb->prefix."posts.aixorder_score DESC, ".$wpdb->prefix."posts.post_date DESC LIMIT 0,17";
		$result = mysql_query($query);

		$title="";
		$success="";
		$novelty="";
		$color_success="";
		$color_novelty="";
		
		if ($result && mysql_num_rows($result) > 0) {
			while ($post = mysql_fetch_array($result)) {
				$new_title = substr($post['title'],0,60);
				if($new_title!=$post['title']){$new_title = $new_title."...";}
				$title = "|".ereg_replace("[ |,\"]","+",$new_title)."+".$title;
				if($success!=""){$success = $success.",";}
				$success = $success.floor((1 - (get_option('aixorder_novelty_coeff')/100))*(($post['success']/get_option('aixorder_popularity_max'))*100));
				if($novelty!=""){$novelty = $novelty.",";}
				$novelty = $novelty.floor((get_option('aixorder_novelty_coeff')/100)* $post['novelty']);
				if($color_success!=""){$color_success = $color_success."|";}
					if($post['sticky']=="1"){
						$color_success = $color_success."333333";
					}else{
						$color_success = $color_success."4d89f9";
					}
				if($color_novelty!=""){$color_novelty = $color_novelty."|";}
					if($post['sticky']=="1"){
						$color_novelty = $color_novelty."8a8a8a";
					}else{
						$color_novelty = $color_novelty."c6d9fd";
					}					
			}
		}
		$graph="http://chart.apis.google.com/chart?cht=bhs&chco=".$color_success.",".$color_novelty."&chtt=".__('in+grey,+posts+forced+on+first+positions','aixorder')."&chxt=x,y&chxl=1:".$title."&chds=0,100&chdl=".__('Popularity|Novelty','aixorder')."&chs=600x500&chd=t:".$success."|".$novelty;
		return $graph;
	}
	
	/******************************************************************************
	* Translation
	*******************************************************************************/	
	
	function aixorder_translate(){
		load_plugin_textdomain('aixorder','wp-content/plugins/aixorder/locales');
	}
	
}

/******************************************************************************
* Main
*******************************************************************************/
/* User callable functions */
function aixorder_top_chart(){
	global $aixorder;
	echo $aixorder->top_chart();
}

/* Plugin construction */
$aixorder = new aixorder;

?>