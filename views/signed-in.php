<?php $this->layout('layout', ['title' => $title]); ?>

<p><strong>Le consentement a bien été obtenu pour le plugin DomoticzLinky ! Vous pouvez fermer cette page et retourner sur Domoticz.</strong></p>

<script>
window.history.replaceState({}, false, '/domoticzlinkyconnect/device');
</script>
