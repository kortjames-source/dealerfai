<?php
// Legacy page: accessory scoring moved to direct action rules.
include 'auth.php';
header('Location: admin_scoring_action_rules.php?prefill_target_type=accessory');
exit;
