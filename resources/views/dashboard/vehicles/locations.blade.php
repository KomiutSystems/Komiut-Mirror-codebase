@extends('layouts.dashboard')

@section('content') <!-- Content Header (Page header) -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0"><i class='fas fa-map-marker-alt'></i> <b>Vehicle</b> Locations</h1>
            </div><!-- /.col -->
            <div class="col-sm-6 text-right">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ url('vehicles/all') }}">Vehicles</a></li>
                    <li class="breadcrumb-item active">Locations</li>
                </ol>
            </div><!-- /.col -->
        </div><!-- /.row -->
    </div><!-- /.container-fluid -->
</div>
<!-- /.content-header -->

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        <!-- Small boxes (Stat box) -->
        <div class="row">
            <div class="col-md-12 mb-3">
                
                    <!-- small box -->
                    <div class="card card-primary card-outline">
                        <div class="card-body box-profile">
                            <div class="card-body">
                                <div class="map-responsive" id="map">
                                    <iframe width="600" height="450" frameborder="0" style="border:0" allowfullscreen>
                                    </iframe>
                                </div>
                            </div>
                        </div>
                    </div>

            </div>
        </div>
    </div>
</div>
@endsection

@push('js')

    <!--   Optional JS   -->
    <script src="https://maps.googleapis.com/maps/api/js?key=***REMOVED***&libraries=places&region=KE"></script>
    <script src="https://rawgit.com/googlemaps/js-map-label/gh-pages/src/maplabel-compiled.js"></script>
    <script src="https://unpkg.com/@googlemaps/markerclustererplus/dist/index.min.js"></script>
    <!-- The core Firebase JS SDK is always required and must be listed first -->
    <script src="https://www.gstatic.com/firebasejs/8.3.1/firebase-app.js"></script>

    <!-- TODO: Add SDKs for Firebase products that you want to use
        https://firebase.google.com/docs/web/setup#available-libraries -->
    <script src="https://www.gstatic.com/firebasejs/8.3.1/firebase-analytics.js"></script>
    <script src="https://www.gstatic.com/firebasejs/3.1.0/firebase.js"></script>

    <script>
        $(document).ready(function(){
            // Your web app's Firebase configuration
            // For Firebase JS SDK v7.20.0 and later, measurementId is optional
            var firebaseConfig = {
                apiKey: "***REMOVED***",
                authDomain: "komiut.firebaseapp.com",
                databaseURL: "https://komiut.firebaseio.com",
                projectId: "komiut",
                storageBucket: "komiut.appspot.com",
                messagingSenderId: "1028247623841",
                appId: "1:1028247623841:web:cccf8d1558ad87011778e0",
                measurementId: "G-EJ0DETMSYT"
            };
            
            // Initialize Firebase
            firebase.initializeApp(firebaseConfig);
            //firebase.analytics();

            // markers array to store all the markers, so that we could remove marker when any car goes offline and its data will be remove from realtime database...
            var markers = [];

            var map;
            getLocation();
            var markerCluster = new MarkerClusterer(map, markers, {
                imagePath:
                "https://developers.google.com/maps/documentation/javascript/examples/markerclusterer/m",
            });

            function showLocation(position) {
                var latitude = position.coords.latitude;
                var longitude = position.coords.longitude;
                var locs = {lat: latitude, lng: longitude};
                initMap(locs);
            }
            function errorHandler(err) {
                if(err.code == 1) {
                alert("Error: Access is denied!");
                } else if( err.code == 2) {
                alert("Error: Position is unavailable!");
                }
            }
            function getLocation(){
                if(navigator.geolocation){
                // timeout at 60000 milliseconds (60 seconds)
                var options = {timeout:60000};
                navigator.geolocation.getCurrentPosition
                (showLocation, errorHandler, options);
                } else{
                alert("Sorry, browser does not support geolocation!");
                }
            }

            function initMap(locs) { // Google Map Initialization...
                map = new google.maps.Map(document.getElementById('map'), {
                    zoom: 10,
                    center: new google.maps.LatLng(locs),
                });
            }
        

            // This Function will create a car icon with angle and add/display that marker on the map
            function AddCar(data) {

                var icon = { // car icon
                    path: 'M29.395,0H17.636c-3.117,0-5.643,3.467-5.643,6.584v34.804c0,3.116,2.526,5.644,5.643,5.644h11.759   c3.116,0,5.644-2.527,5.644-5.644V6.584C35.037,3.467,32.511,0,29.395,0z M34.05,14.188v11.665l-2.729,0.351v-4.806L34.05,14.188z    M32.618,10.773c-1.016,3.9-2.219,8.51-2.219,8.51H16.631l-2.222-8.51C14.41,10.773,23.293,7.755,32.618,10.773z M15.741,21.713   v4.492l-2.73-0.349V14.502L15.741,21.713z M13.011,37.938V27.579l2.73,0.343v8.196L13.011,37.938z M14.568,40.882l2.218-3.336   h13.771l2.219,3.336H14.568z M31.321,35.805v-7.872l2.729-0.355v10.048L31.321,35.805',
                    scale: .8,
                    fillColor: "#427af4", //<-- Car Color, you can change it
                    fillOpacity: 1,
                    strokeWeight: 1,
                    anchor: new google.maps.Point(0, 5),
                    rotation: data.val().bearing //<-- Car angle
                };

                var uluru = { lat: parseFloat(data.val().latitude), lng: parseFloat(data.val().longitude) };

                var marker = new google.maps.Marker({
                    position: uluru,
                    icon: icon,
                    map: map,
                    label: data.key
                });

                marker.info = new google.maps.InfoWindow({
                    content: data.key,
                });

                google.maps.event.addListener(marker, 'click', function() {
                    marker.info.open(map, marker);
                });

                markers[data.key] = marker; // add marker in the markers array...

                markerCluster.clearMarkers();

                markerCluster = new MarkerClusterer(map, markers, {
                    imagePath:
                    "https://developers.google.com/maps/documentation/javascript/examples/markerclusterer/m",
                });
            }

            // get firebase database reference...
            var cars_Ref = firebase.database().ref('/Locations');

            // this event will be triggered when a new object will be added in the database...
            cars_Ref.on('child_added', function (data) {
                //alert('car added');
                AddCar(data);
            });

            // this event will be triggered on location change of any car...
            cars_Ref.on('child_changed', function (data) {
                //alert('car changed');
                markers[data.key].setMap(null);
                AddCar(data);
            });

            // If any car goes offline then this event will get triggered and we'll remove the marker of that car...
            cars_Ref.on('child_removed', function (data) {
                //alert('removed');
                markers[data.key].setMap(null);
            });
        });
    </script>
@endpush
