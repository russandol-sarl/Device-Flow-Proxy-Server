<?php $this->layout('layout', ['title' => 'Entrez le code', 'base_url' => $base_url]); ?>

<p>Enedis gère le réseau d’électricité jusqu’au compteur d’électricité. Cette page a pour but de lancer le consentement vous permettant d'autoriser Enedis à transmettre vos données Linky au plugin DomoticzLinky.</p>

<p>Pour donner votre autorisation, vous devez créer un compte personnel <a href="https://www.enedis.fr">Enedis</a>. Il vous permet également de suivre et gérer vos données de consommation et/ou de production en fonction de votre service d’électricité. Munissez-vous de votre facture d’électricité pour créer votre espace si vous ne l'avez pas déjà fait.</p>

<p>Si vous êtes sur cette page, c'est que le plugin DomoticzLinky a dû vous inviter à vous y rendre par un message dans Configuration / Log. Attention, si vous avez trop tardé, le code n'est peut-être plus valable. Pour relancer le processus et obtenir un code de nouveau valable, rendez-vous sous Domoticz dans Configuration / Matériel, cliquez sur le plugin et sur Modifier et surveillez les messages dans Configuration / Log.</p>

<p>En cliquant sur le bouton "Envoyer" ci-dessous, vous allez accéder à votre compte personnel Enedis où vous pourrez donner votre accord pour qu’Enedis transmette vos données au plugin DomoticzLinky.</p>

<?php if($code): ?>
<p>Veuillez confirmer que le code ci-dessous correspond au code donné dans les logs de Domoticz (dans Configuration / Log) par le plugin DomoticzLinky.</p>
<?php else: ?>
<p>Pour continuer, entrez ci-dessous le code obtenu dans les logs de Domoticz (dans Configuration / Log).</p>
<?php endif ?>

<form action="<?= $base_url ?>/auth/verify_code" method="get">
  <input type="text" name="code" placeholder="XXXX-XXXX" id="user_code" value="<?= $code ?>" autocomplete="off">
  <input type="hidden" name="state" value="<?= $state ?>">
  <input type="submit">
</form>

<script>
document.getElementById("user_code").focus();
</script>
