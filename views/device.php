<?php $this->layout('layout', ['title' => 'Entrez le code']); ?>

<?php if($code): ?>
<p>Veuillez confirmer que le code ci-dessous correspond au code donnée dans les logs de Domoticz par le plugin DomoticzLinky.</p>
<?php else: ?>
<p>Entrez le code obtenu dans les logs de Domoticz ci-dessous pour continuer.</p>
<?php endif ?>

<form action="https://opensrcdev.alwaysdata.net/domoticzlinkyconnect/auth/verify_code" method="get">
  <input type="text" name="code" placeholder="XXXX-XXXX" id="user_code" value="<?= $code ?>" autocomplete="off">
  <input type="submit">
</form>

<script>
document.getElementById("user_code").focus();
</script>
