<?php
$cfAdminViewModel = $cfAdminViewModel ?? [];
$LANG = $LANG ?? [];
$lang = $LANG['domainHub'] ?? [];
$inviteRegLogsView = $cfAdminViewModel['inviteRegistrationLogs'] ?? [];
$inviteRegItems = $inviteRegLogsView['items'] ?? [];
$inviteRegSearch = $inviteRegLogsView['search'] ?? '';
$pagination = $inviteRegLogsView['pagination'] ?? [];
$inviteRegPage = max(1, (int) ($pagination['page'] ?? 1));
$inviteRegTotalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
$inviteRegTotal = max(0, (int) ($pagination['total'] ?? 0));
$perPage = max(1, (int) ($pagination['perPage'] ?? 20));

$title = $lang['invite_reg_logs_title'] ?? '邀请注册日志';
$searchPlaceholder = $lang['invite_reg_logs_search_placeholder'] ?? '邮箱或邀请码';
$searchButton = $lang['invite_reg_logs_search_button'] ?? '搜索';
$resetButton = $lang['invite_reg_logs_reset_button'] ?? '重置';
$migrateButton = $lang['invite_reg_migrate_button'] ?? '为老用户自动解锁';
$migrateConfirm = $lang['invite_reg_migrate_confirm'] ?? '确定要为所有老用户自动解锁邀请注册限制吗？此操作将检测已有域名、配额等记录的用户并自动为其解锁。';
$codeLabel = $lang['invite_reg_logs_code'] ?? '邀请码';
$inviterLabel = $lang['invite_reg_logs_inviter'] ?? '邀请人';
$inviteeLabel = $lang['invite_reg_logs_invitee'] ?? '被邀请人';
$inviteeEmailLabel = $lang['invite_reg_logs_invitee_email'] ?? '被邀请人邮箱';
$inviteeIpLabel = $lang['invite_reg_logs_invitee_ip'] ?? '注册 IP';
$timeLabel = $lang['invite_reg_logs_time'] ?? '注册时间';
$emptyLabel = $lang['invite_reg_logs_empty'] ?? '暂无邀请注册记录';
$autoUnlockedNote = $lang['invite_reg_auto_unlocked_note'] ?? '此功能启用后，系统会自动检测老用户（已注册域名/配额记录等）并自动解锁，无需输入邀请码。';

$currentUrl = $_SERVER['REQUEST_URI'] ?? '';
$parsed = $currentUrl !== '' ? @parse_url($currentUrl) : false;
$basePath = ($parsed && !empty($parsed['path'])) ? $parsed['path'] : ($_SERVER['PHP_SELF'] ?? '');
$queryArgs = [];
if ($parsed && !empty($parsed['query'])) {
    parse_str($parsed['query'], $queryArgs);
}
$queryArgs['m'] = $_GET['m'] ?? 'domain_hub';
unset($queryArgs['invite_reg_page']);
$buildUrl = function (array $params) use ($basePath) {
    $query = http_build_query($params);
    return $basePath . ($query === '' ? '' : '?' . $query);
};
$resetArgs = $queryArgs;
unset($resetArgs['invite_reg_search']);
$resetUrl = $buildUrl($resetArgs) . '#invite-reg-logs';
?>
<div class="card mb-4" id="invite-reg-logs">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="card-title mb-0"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($title); ?></h5>
      <form method="post" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($migrateConfirm, ENT_QUOTES); ?>');">
        <?php if (function_exists('cfmod_csrf_hidden_field')) { echo cfmod_csrf_hidden_field(); } ?>
        <input type="hidden" name="action" value="migrate_invite_registration_existing_users">
        <button type="submit" class="btn btn-sm btn-outline-success" title="<?php echo htmlspecialchars($autoUnlockedNote, ENT_QUOTES); ?>">
          <i class="fas fa-magic"></i> <?php echo htmlspecialchars($migrateButton); ?>
        </button>
      </form>
    </div>
    <div class="alert alert-info small mb-3">
      <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($autoUnlockedNote); ?>
    </div>
    <form method="get" class="row g-2 align-items-center mb-3">
      <input type="hidden" name="m" value="<?php echo htmlspecialchars($queryArgs['m'] ?? 'domain_hub'); ?>">
      <?php
      foreach ($queryArgs as $key => $val) {
          if (in_array($key, ['m', 'invite_reg_search'], true)) { continue; }
          echo '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES) . '" value="' . htmlspecialchars(is_array($val) ? '' : (string) $val, ENT_QUOTES) . '">';
      }
      ?>
      <div class="col-sm-6 col-md-4">
        <input type="text" name="invite_reg_search" class="form-control" placeholder="<?php echo htmlspecialchars($searchPlaceholder); ?>" value="<?php echo htmlspecialchars($inviteRegSearch); ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($searchButton); ?></button>
      </div>
      <div class="col-auto">
        <a href="<?php echo htmlspecialchars($resetUrl); ?>" class="btn btn-outline-secondary"><?php echo htmlspecialchars($resetButton); ?></a>
      </div>
    </form>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th><?php echo htmlspecialchars($codeLabel); ?></th>
            <th><?php echo htmlspecialchars($inviterLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeEmailLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeIpLabel); ?></th>
            <th><?php echo htmlspecialchars($timeLabel); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($inviteRegItems)): ?>
            <?php foreach ($inviteRegItems as $item): ?>
              <tr>
                <td><code><?php echo htmlspecialchars($item['invite_code'] ?? ''); ?></code></td>
                <td>
                  <?php if (!empty($item['inviter_userid'])): ?>
                    <span class="text-muted">#<?php echo intval($item['inviter_userid']); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($item['inviter_email'])): ?>
                    <div><?php echo htmlspecialchars($item['inviter_email']); ?></div>
                  <?php elseif (intval($item['inviter_userid'] ?? 0) === 0): ?>
                    <span class="badge bg-secondary">管理员</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($item['invitee_userid'])): ?>
                    <span class="text-muted">#<?php echo intval($item['invitee_userid']); ?></span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($item['invitee_email'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($item['invitee_ip'] ?? '-'); ?></td>
                <td><small class="text-muted"><?php echo htmlspecialchars($item['created_at'] ?? '-'); ?></small></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-4"><?php echo htmlspecialchars($emptyLabel); ?></td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($inviteRegTotalPages > 1): ?>
      <?php
      $prevPage = max(1, $inviteRegPage - 1);
      $nextPage = min($inviteRegTotalPages, $inviteRegPage + 1);
      $baseArgs = $queryArgs;
      ?>
      <nav aria-label="Invite Registration Logs Pagination" class="mt-3">
        <ul class="pagination pagination-sm">
          <?php $baseArgs['invite_reg_page'] = $prevPage; ?>
          <li class="page-item <?php echo $inviteRegPage <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#invite-reg-logs'); ?>">&laquo;</a>
          </li>
          <?php for ($i = 1; $i <= $inviteRegTotalPages; $i++): ?>
            <?php $baseArgs['invite_reg_page'] = $i; ?>
            <li class="page-item <?php echo $inviteRegPage === $i ? 'active' : ''; ?>">
              <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#invite-reg-logs'); ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <?php $baseArgs['invite_reg_page'] = $nextPage; ?>
          <li class="page-item <?php echo $inviteRegPage >= $inviteRegTotalPages ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#invite-reg-logs'); ?>">&raquo;</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>
