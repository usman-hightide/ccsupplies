<?php 
public function routes() {
    register_rest_route( self::NS, '/users', [
        'methods'  => 'GET',
        'permission_callback' => [ $this, 'can_manage' ],
        'callback' => [ $this, 'list_users' ],
    ]);

    // NEW: create user
    register_rest_route( self::NS, '/users', [
        'methods'  => 'POST',
        'permission_callback' => [ $this, 'can_manage' ], // same cap as your other manager endpoints
        'callback' => [ $this, 'create_user' ],
        'args'     => [
            'first_name' => ['required'=>true,  'sanitize_callback'=>'sanitize_text_field'],
            'last_name'  => ['required'=>false, 'sanitize_callback'=>'sanitize_text_field'],
            'email'      => ['required'=>true,  'sanitize_callback'=>'sanitize_email'],
            // optional: if omitted, we’ll auto-generate a secure password
            'password'   => ['required'=>false, 'sanitize_callback'=>'sanitize_text_field'],
        ],
    ]);
}