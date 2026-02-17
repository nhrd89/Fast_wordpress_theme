<?php
add_action('wp_footer', 'pinlightning_depth_effects_loader', 99);
function pinlightning_depth_effects_loader() {
    if (!is_single()) return;
    $css_url = get_template_directory_uri() . '/assets/css/depth-effects.css';
    $js_url  = get_template_directory_uri() . '/assets/js/depth-effects.js';
    $ver = '1.0.0';
    ?>
    <script>
    (function(){
      var loaded=false;
      function loadDepth(){
        if(loaded)return;loaded=true;
        var link=document.createElement('link');
        link.rel='stylesheet';
        link.href='<?php echo esc_url($css_url); ?>?v=<?php echo $ver; ?>';
        document.head.appendChild(link);
        link.onload=function(){
          var s=document.createElement('script');
          s.src='<?php echo esc_url($js_url); ?>?v=<?php echo $ver; ?>';
          document.body.appendChild(s);
        };
        window.removeEventListener('scroll',loadDepth);
        document.removeEventListener('touchstart',loadDepth);
      }
      window.addEventListener('scroll',loadDepth,{once:true,passive:true});
      document.addEventListener('touchstart',loadDepth,{once:true,passive:true});
      if('requestIdleCallback' in window){requestIdleCallback(function(){setTimeout(loadDepth,4000);});}
      else{setTimeout(loadDepth,5000);}
    })();
    </script>
    <?php
}
add_filter('body_class', 'pinlightning_depth_category_class');
function pinlightning_depth_category_class($classes) {
    if (is_single()) {
        $categories = get_the_category();
        if ($categories) {
            foreach ($categories as $cat) {
                $classes[] = 'category-' . $cat->slug;
            }
        }
    }
    return $classes;
}
