<?php include_once('includes/header.php'); ?>

<?php

	if (isset($_GET['id'])) {
		$ID = clean($_GET['id']);
	} else {
		$ID = "";
	}

	$qry    = "SELECT * FROM tbl_recipes WHERE recipe_id = '$ID'";
    $result = mysqli_query($connect, $qry);
    $data    = mysqli_fetch_assoc($result);

?>	

<section class="content">

	<ol class="breadcrumb breadcrumb-offset">
		<li><a href="dashboard.php">Dashboard</a></li>
		<li><a href="recipes.php">Manage Recipes</a></li>
		<li class="active">Recipes Detail</a></li>
	</ol>

	<div class="container-fluid" id="fade-in">

		<div class="row clearfix">
			<div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
				<form method="post">
					<div class="card corner-radius">
						<div class="header">
							<h2>RECIPES DETAIL</h2>
							<div class="header-dropdown m-r--5" style="margin-right: 10px; display:flex; align-items:center; gap:8px;">
                                <?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
                                <a href="ai-generator.php" class="btn btn-xs bg-purple waves-effect" style="color:white;"><i class="material-icons" style="font-size:14px;vertical-align:middle;">auto_awesome</i> AI Foto</a>
                                <button type="button" id="btn-detail-sync" class="btn btn-xs bg-cyan waves-effect" style="color:white;"><i class="material-icons" style="font-size:14px;vertical-align:middle;">cloud_upload</i> 🚀 Push ke Web Hosting</button>
                                <?php endif; ?>
                                <a href="recipes-edit.php?id=<?php echo $data['recipe_id'];?>" title="Edit"><i class="material-icons">mode_edit</i></a>
								<a href="recipes-delete.php?id=<?php echo $data['recipe_id'];?>" onclick="return confirm('Are you sure want to delete this Recipe?')" title="Hapus"><i class="material-icons">delete</i></a>
                            </div>
						</div>
						<div class="body">

							<div class="row clearfix">

								<div class="form-group form-float col-sm-12">

									<?php if (isset($_SESSION['msg'])) { ?>
									<div class='alert alert-info alert-dismissible corner-radius bottom-offset' role='alert'>
										<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span>&nbsp;&nbsp;</button>
										<?php echo $_SESSION['msg']; ?>
									</div>
									<?php unset($_SESSION['msg']); } ?>

									<?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
									<!-- Sync Status Banner -->
									<div id="detail-sync-banner" style="margin-bottom: 20px;">
										<?php if (!empty($data['live_recipe_id'])) { ?>
										<div class="alert alert-success" style="border-radius:6px; padding:12px 16px;">
											<strong><i class="material-icons" style="vertical-align:middle;font-size:18px;">check_circle</i> Terbit di Web Hosting:</strong> Resep ini sudah live di hosting <a href="https://airfry.web.id" target="_blank" style="color:#fff; text-decoration:underline;">airfry.web.id</a> (ID: #<?php echo $data['live_recipe_id'];?>).
											<small style="display:block; margin-top:4px; opacity:0.9;">Terakhir disinkron: <?php echo $data['last_synced_at'] ?? '-';?></small>
										</div>
										<?php } else { ?>
										<div class="alert alert-warning" style="border-radius:6px; padding:12px 16px;">
											<strong><i class="material-icons" style="vertical-align:middle;font-size:18px;">cloud_off</i> Status Lokal:</strong> Resep ini baru ada di database lokal, belum dipublikasikan ke web hosting live.
										</div>
										<?php } ?>
									</div>
									<?php endif; ?>

									<p>
										<h4>
											<?php echo $data['recipe_title']; ?>
										</h4>
									</p>
									<p>
										<i class="material-icons" style="font-size:16px; vertical-align:middle; color:#777;">schedule</i> <?php echo $data['recipe_time']; ?> 
									</p>

									<?php if ($data['content_type'] == 'youtube') { ?>
									<p><img class="img-corner-radius" style="max-width:40%" src="https://img.youtube.com/vi/<?php echo $data['video_id'];?>/mqdefault.jpg" ></p>
									<?php } else { ?>
									<p><img class="img-corner-radius" style="max-width:40%" src="upload/<?php echo $data['recipe_image']; ?>" onerror="this.src='assets/images/no-image.png'"></p>
									<?php } ?>

									<p><?php echo $data['recipe_description']; ?></p>

								</form>

								</div>
							</div>
						</div>
					</div>

				</div>

			</div>

		</div>

	</section>

	<script>
	document.addEventListener('DOMContentLoaded', function() {
		var btnSync = document.getElementById('btn-detail-sync');
		if (btnSync) {
			btnSync.addEventListener('click', function() {
				var originalHtml = btnSync.innerHTML;
				btnSync.disabled = true;
				btnSync.innerHTML = '<i class="material-icons" style="font-size:14px;vertical-align:middle;">hourglass_empty</i> Mengunggah...';

				var formData = new URLSearchParams();
				formData.append('action', 'push_single');
				formData.append('recipe_id', '<?php echo $data['recipe_id']; ?>');

				fetch('ajax_sync.php', {
					method: 'POST',
					body: formData
				})
				.then(function(r) { return r.json(); })
				.then(function(res) {
					btnSync.disabled = false;
					btnSync.innerHTML = originalHtml;
					if (res.success) {
						var banner = document.getElementById('detail-sync-banner');
						if (banner) {
							banner.innerHTML = '<div class="alert alert-success" style="border-radius:6px; padding:12px 16px;">' +
								'<strong><i class="material-icons" style="vertical-align:middle;font-size:18px;">check_circle</i> Terbit di Web Hosting:</strong> ' + (res.message || 'Resep berhasil dipublikasikan ke hosting!') +
								'<div style="margin-top:8px;"><a href="https://airfry.web.id" target="_blank" class="btn btn-xs bg-white waves-effect" style="color:#2e7d32; font-weight:bold;"><i class="material-icons" style="font-size:12px;vertical-align:middle;">open_in_new</i> Lihat di Web Hosting</a></div>' +
								'</div>';
						}
						alert('Resep berhasil dipublikasikan ke hosting airfry.web.id (ID: #' + res.live_recipe_id + ')!');
					} else {
						alert('Gagal: ' + (res.error || 'Terjadi kesalahan saat push ke hosting.'));
					}
				})
				.catch(function(err) {
					btnSync.disabled = false;
					btnSync.innerHTML = originalHtml;
					alert('Kesalahan jaringan: ' + err.message);
				});
			});
		}
	});
	</script>

	<?php include_once('includes/footer.php'); ?>