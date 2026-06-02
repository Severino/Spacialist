import useUserStore from '@/bootstrap/stores/user.js';

export const handleUserLogout = {
    'UserLogout': data => {
        // Logout the user if the user that logged out is the current user
        // If the user was logged in from multiple devices, and the server invalidates
        // it's token, he should be automatically logged out.
        // Note: Currently this is not possible as we are not using a database to store the tokens
        //       So this should be enabled when the backend can invalidate tokens per user efficiently.
        // if(data.id == useUserStore().getCurrentUserId) {
        //     useUserStore().setLoggedOutState();
        // }
    }
};