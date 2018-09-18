jQuery(document).ready(function($) {

    console.log('working')

    // console.log(admin.order);

    // getToken();

    function getToken() {
        $.ajax({
            type: 'post',
            contentType: 'application/json',
            url: 'https://dev-app.di.no/ws/json/auth/v-2/login',
            data: {
                'username': 'wsRonaldosBrd',
                'password': 'unhjocrjufihb9d7jp0obkhppc'
            },
            success: successTokenResponse
        });
    }

    function successTokenResponse(data) {
        console.log(data);
    }
});