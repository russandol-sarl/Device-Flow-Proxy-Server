<?php $this->layout('layout', ['title' => 'Consentement obtenu', 'base_url' => $base_url]); ?>

<h2>Le consentement a bien été obtenu pour le plugin DomoticzLinky ! Vous pouvez fermer cette page et retourner sur Domoticz.</h2>

<script>
window.history.replaceState({}, false, '<?= $base_url ?>/device');
</script>
