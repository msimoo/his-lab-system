<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');

$entry_id = intval($_POST['entry_id']);

$entry = $mysqli->query("SELECT * FROM rpos_journal_entries WHERE entry_id = $entry_id")->fetch_assoc();

?>
<div class="alert alert-info">
    <strong>رقم القيد:</strong> <?= $entry['entry_id'] ?> | 
    <strong>التاريخ:</strong> <?= $entry['entry_date'] ?> | 
    <strong>الحالة:</strong> <span class="badge badge-success"><?= $entry['status'] ?></span>
</div>

<table class="table table-sm table-bordered">
    <thead class="bg-light">
        <tr>
            <th>الحساب</th>
            <th class="text-right">مدين</th>
            <th class="text-right">دائن</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $items = $mysqli->query("
            SELECT 
                ji.*,
                (SELECT account_name FROM rpos_accounts WHERE account_id = ji.account_id) as account_name,
                (SELECT account_code FROM rpos_accounts WHERE account_id = ji.account_id) as account_code
            FROM rpos_journal_items ji
            WHERE entry_id = $entry_id
        ");
        
        $total_debit = 0;
        $total_credit = 0;
        
        while($item = $items->fetch_assoc()):
            $total_debit += $item['debit'];
            $total_credit += $item['credit'];
        ?>
        <tr>
            <td><?= $item['account_code'] . ' - ' . $item['account_name'] ?></td>
            <td class="text-right"><?= $item['debit'] > 0 ? number_format($item['debit'], 2) : '' ?></td>
            <td class="text-right"><?= $item['credit'] > 0 ? number_format($item['credit'], 2) : '' ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
    <tfoot class="font-weight-bold bg-light">
        <tr>
            <th>الإجمالي</th>
            <th class="text-right"><?= number_format($total_debit, 2) ?></th>
            <th class="text-right"><?= number_format($total_credit, 2) ?></th>
        </tr>
    </tfoot>
</table>

<?php if(abs($total_debit - $total_credit) < 0.01): ?>
    <div class="alert alert-success">✓ القيد متوازن (مدين = دائن)</div>
<?php else: ?>
    <div class="alert alert-danger">✗ تحذير: القيد غير متوازن!</div>
<?php endif; ?>
