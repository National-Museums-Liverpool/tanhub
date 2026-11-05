<?php
$messages = [];
$collectMessages = static function (mixed $value) use (&$collectMessages, &$messages): void {
    if (is_array($value)) {
        foreach ($value as $nestedValue) {
            $collectMessages($nestedValue);
        }

        return;
    }

    if (is_scalar($value) && trim((string) $value) !== '') {
        $messages[] = (string) $value;
    }
};

$collectMessages(session('errors') ?? []);
$collectMessages(session('mediaErrors') ?? []);
$collectMessages(session('error') ?? '');
$messages = array_values(array_unique($messages));
?>
<?php if ($messages !== []): ?>
    <div id="validation-error-summary" class="alert alert-danger mt-3" role="alert" aria-labelledby="validation-error-heading" tabindex="-1" autofocus>
        <h2 class="h5 alert-heading" id="validation-error-heading">Your changes were not saved</h2>
        <p>Please correct the following and try again:</p>
        <ul class="mb-0">
            <?php foreach ($messages as $message): ?>
                <li><?= esc($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
