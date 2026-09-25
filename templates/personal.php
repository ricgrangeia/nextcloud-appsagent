<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="appsagent-personal-settings" class="section">
	<h2><?php p($l->t('Apps Agent — Telegram')); ?></h2>

	<?php if ($_['linked']): ?>
		<p>
			<?php p($l->t('O teu Telegram (ID %s) esta ligado a esta conta.', [$_['chatId']])); ?>
		</p>
	<?php else: ?>
		<p>
			<?php p($l->t('O teu Telegram ainda nao esta ligado a esta conta Nextcloud. Sem isto, o bot Telegram nao sabe que utilizador Nextcloud es tu.')); ?>
		</p>

		<?php if ($_['pendingCode'] !== ''): ?>
			<p>
				<?php p($l->t('Codigo de ligacao (expira em breve):')); ?>
				<strong><?php p($_['pendingCode']); ?></strong>
			</p>
			<p><?php p($l->t('Manda este codigo como mensagem ao bot no Telegram para completares a ligacao.')); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php p($_['generateUrl']); ?>">
			<input type="hidden" name="requesttoken" value="<?php p(\OCP\Util::callRegister()); ?>">
			<button type="submit" class="button">
				<?php p($l->t('Gerar codigo de ligacao')); ?>
			</button>
		</form>
	<?php endif; ?>
</div>
