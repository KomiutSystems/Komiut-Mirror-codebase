<!DOCTYPE html>
<html>

<head>
    <style>

        @font-face {
            font-family: 'Poppins';
            src: url({{ storage_path('fonts/Poppins-Bold.ttf') }}) format("truetype");
            font-weight: 700;
            font-style: normal;
        }

        @font-face {
            font-family: 'Poppins';
            src: url({{ storage_path('fonts/Poppins-Light.ttf') }}) format("truetype");
            font-weight: 200;
            font-style: normal;
        }

        @font-face {
            font-family: 'Poppins';
            src: url({{ storage_path('fonts/Poppins-Regular.ttf') }}) format("truetype");
            font-weight: 300;
            font-style: normal;
        }

        body {
            font-family: "poppins";
            font-size: 0.8em;
        }

        p {
            font-weight: 300;
        }

        .img-responsive {
            max-width: 100px !important;
        }

        .m-0 {
            margin: 0
        }

        .table {
            width: 100%;
            margin-bottom: 1rem;
            color: #212529;
            background-color: transparent;
            border-spacing: 0;
        }

        .text-right {
            text-align: right;
        }

        .table-header {
            font-weight: bold;
        }
        .text-primary{
            color:#BE3144;
            font-weight: 300;
        }

        thead th{
            text-align: left;
            background-color: #e3e7ec;
            border-top: 1px solid #BE3144;
            text-transform: uppercase;
        }

        thead th {
            text-align: left;
            background-color: #e3e7ec;
        }

        .invoices tbody td {
            /*border-bottom: 1px solid #e3e7ec;*/
        }


        tfoot td{
            /*border-bottom: 1px solid #BE3144;
            border-top: 1px solid #BE3144;*/
            background-color: #e3e7ec;
        }

        @page {
            margin: 25px 25px 100px 25px;
        }

        header {
            position: fixed;
            top: -60px;
            left: 0px;
            right: 0px;
            height: 50px;

            /** Extra personal styles **/
            background-color: #03a9f4;
            color: white;
            text-align: center;
            line-height: 35px;
        }

        footer {
            position: fixed;
            bottom: -60px;
            left: 0px;
            right: 0px;
            height: 50px;

            /** Extra personal styles **/
            /*background-color: #03a9f4;*/
            color: white;
            text-align: center;
            line-height: 35px;
        }
    </style>
</head>

<body>
    <!-- <header>
        Our Code World
    </header>
-->
    <footer>
        <script type="text/php">
            if ( isset($pdf) ) {
                $font = $fontMetrics->getFont("Poppins", "normal");
                $pdf->page_text(261, 790, "Page: {PAGE_NUM} of {PAGE_COUNT}", $font, 10, array(0,0,0));
            }
        </script>
    </footer>
    <table class="table">
        <tr>
            <td style='vertical-align:top;'>
                <h2>{{ $vehicle->plate }}</h2>
            </td>
            <td width="30%" style='float:right'>
                <img src='data:image/jpeg;base64,{{ base64_encode(QrCode::format('svg')->size(200)->errorCorrection('H')->generate('' . url('/pay_online') . '?till_number=' . $vehicle->till_number)) }}' class='img-fluid img-responsive' />
                <!--<p class='m-0' style='text-transform: uppercase; font-weight:300;'>{{ $vehicle->plate }}</p>-->
            </td>
        </tr>
    </table>
    <h4>Seats QR Code</h4>
    <table class='table invoices'>
    @for ($i = 1; $i <= $vehicle->seat->rows; $i++)
    <tr>
            @for ($j = 1; $j <= $vehicle->seat->columns; $j++)

                @php
                    $myseat = $vehicle->seat->seat_arrangements
                        ->where('row', $i)
                        ->where('column', $j)
                        ->first();
                    if ($myseat != null) {
                        echo "<td><img src='data:image/jpeg;base64,".base64_encode(QrCode::format('svg')->size(200)->errorCorrection('H')->generate('' . url('/pay_online') . '?till_number=' . $vehicle->till_number.'&seat='.$myseat->id))."' class='img-fluid img-responsive' /><div class='text-primary'>" . $myseat->name . ' </div></td>';
                    } else {
                        echo "<td></td>";
                    }
                @endphp
            @endfor

        </tr>
        @endfor
        </table>
</body>

</html>
