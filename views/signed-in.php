<?php $this->layout('layout', ['title' => $title]); ?>

<p>Le consentement a bien été obtenu pour le plugin DomoticzLinky ! Vous pouvez fermer cette page et retourner sur Domoticz.</p>

<script>
window.history.replaceState({}, false, '/auth/redirect');
</script>
