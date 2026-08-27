<?php
/**
 * viewCourt.php
 *
 * Sport venue detail page.
 * Displays courts according to selected sport.
 *
 * Example:
 * viewCourt.php?sport=1
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';


/*
|--------------------------------------------------------------------------
| Get selected sport ID
|--------------------------------------------------------------------------
*/

$sport_id = isset($_GET['sport']) ? (int)$_GET['sport'] : 0;


if ($sport_id <= 0) {
    die("Invalid sport selection.");
}



/*
|--------------------------------------------------------------------------
| Get sport information
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT 
        sport_type_id,
        name,
        price_per_hour

    FROM sport_types

    WHERE sport_type_id = ?
");


$stmt->bind_param("i", $sport_id);

$stmt->execute();


$sport = $stmt->get_result()->fetch_assoc();



if (!$sport) {
    die("Sport not found.");
}




/*
|--------------------------------------------------------------------------
| Get relevant sports for category section
|--------------------------------------------------------------------------
*/

$sport = $conn->prepare("

    SELECT
        st.sport_type_id,
        st.name,
        st.price_per_hour,

        COUNT(c.court_id) AS court_count

    FROM sport_types st

    LEFT JOIN courts c
        ON st.sport_type_id = c.sport_type_id
        AND c.status = 'active'

    WHERE st.sport_type_id = ?

    GROUP BY st.sport_type_id

");


$sport->bind_param("i", $sport_id);

$sport->execute();


$sport = $sport->get_result()->fetch_assoc();







/*
|--------------------------------------------------------------------------
| Get courts under selected sport
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("

    SELECT
        court_id,
        court_number

    FROM courts

    WHERE sport_type_id = ?

    AND status='active'

    ORDER BY court_id

");


$stmt->bind_param("i",$sport_id);

$stmt->execute();


$courts = $stmt->get_result();




$page_title = $sport['name']." Courts";


require_once __DIR__ . '/../includes/header.php';

?>



<!-- ================= VENUE HEADER ================= -->


<section class="card venue-header">
    <div class="detail-layout">

        <h1>
        <?= h($sport['name']) ?> Court
        </h1>


        <p>
        Kuala Lumpur, Malaysia
        </p>
        
    </div>

    <div class="venue-actions">


        <a href="<?=h(app_url('/booking/search.php?sport='.$sport_id))?>"
        class="btn">

        📅 Book Now

        </a>


        </div>
    </div>


</section>




<!-- ================= SPORT IMAGE ================= -->


<?php
$images = courtImages($sport['name']);
?>


<section class="gallery">


<div class="main-image">

    <img src="<?=h($images[0])?>"
         alt="<?=h($sport['name'])?> Court"
         onclick="openImage(this.src)">

</div>



<div class="small-images">

<?php foreach(array_slice($images,1) as $img): ?>

    <img src="<?=h($img)?>"
         alt="<?=h($sport['name'])?> Court"
         onclick="openImage(this.src)">

<?php endforeach; ?>


</div>


</section>




<!-- ================= INFORMATION AREA ================= -->


<section class="venue-content">


<!-- LEFT COLUMN -->

<div class="left-column">


    <!-- Categories -->

    <div class="card">

        <h2>
            Categories & Pricing
        </h2>


        <div class="category-list">


            <div class="category-box">

                <h3>
                    <?= h($sport['name']) ?>
                </h3>


                <p>
                    <?= $sport['court_count'] ?> courts
                </p>


                <p>
                    <?= money($sport['price_per_hour']) ?> / hour
                </p>


            </div>


        </div>


    </div>





 <!-- Amenities -->
        <div class="amenities-card">

            <h2>Amenities</h2>

            <div class="amenities-list">

                <div class="amenity">
                    <span>Ⓟ Parking</span>
                </div>

                <div class="amenity">
                    <span>🚿 Shower</span>
                </div>

                <div class="amenity">
                    <span>🛍 Pro Shop</span>
                </div>

                <div class="amenity">
                    <span>🥤 Drinks</span>
                </div>

                <div class="amenity">
                    <span>🕌 Surau</span>
                </div>

            </div>

        </div>



</div>


<!-- RIGHT -->


<div class="card venue-card">

    <h2>
    Venue Information
    </h2>

    <div class="info-row">

        🕒 Opening Hours

        <br><br>
        <p>Monday - Sunday: 8:00 AM - 11:00 PM</p>

    </div>

    <div class="info-row">

        📄 Venue Policy

        <br><br>
            <p>1. All bookings are final. No cancellations or changes are allowed.</p>
            <p>2. Please do not leave your valuables unattended. We will not be responsible for any theft.</p>

    </div>

</div>

</section>  


<!-- Image Popup -->

<div id="imageModal" class="image-modal" onclick="closeImage()">

    <span class="close-btn">
        &times;
    </span>


    <img id="popupImage"
         class="popup-content"
         onclick="event.stopPropagation()">

</div>

<script>

function openImage(src)
{
    document.getElementById("imageModal").style.display = "block";

    document.getElementById("popupImage").src = src;
}



function closeImage()
{
    document.getElementById("imageModal").style.display = "none";
}


</script>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>

