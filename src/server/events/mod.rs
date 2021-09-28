mod alert_notification;
mod command_request;
mod command_response;
mod lag_compensation;
mod request_punishment;
mod server_send_data;
mod update_user;

use alert_notification::*;
use command_request::*;
use command_response::*;
use lag_compensation::*;
use request_punishment::*;
use server_send_data::*;
use update_user::*;

#[macro_export]
macro_rules! can_io {
    (struct $ident:ident {$(
        $vis:vis $field:ident : $ty:ty,
    )*}) => {
        #[derive(Debug, Clone)]
        pub struct $ident {
            $(
                $vis $field: $ty,
            )*
        }

        #[allow(unused_variables)]
        impl lumine_protocol::CanIo for $ident {
            fn write(&self, vec: &mut Vec<u8>) {
                $(
                    lumine_protocol::CanIo::write(&self.$field, &mut *vec);
                )*
            }

            fn read(src: &[u8], offset: &mut usize) -> Result<Self, lumine_protocol::DecodeError> {
                Ok(Self {
                    $(
                        $field: lumine_protocol::CanIo::read(src, &mut *offset)?,
                    )*
                })
            }
        }
    };
    (enum $ident:ident : $disty:ty {$(
        $var:ident = $disc:literal,
    )*}) => {
        #[derive(Debug, Clone)]
        pub enum $ident {
            $(
                $var($var),
            )*
        }

        impl lumine_protocol::CanIo for $ident {
            fn write(&self, vec: &mut Vec<u8>) {
                match self {
                    $(
                        Self::$var(value) => {
                            <$disty as lumine_protocol::CanIo>::write(&$disc, &mut *vec);
                            lumine_protocol::CanIo::write(value, &mut *vec);
                        },
                    )*
                }
            }

            fn read(src: &[u8], offset: &mut usize) -> Result<Self, lumine_protocol::DecodeError> {
                match <$disty as lumine_protocol::CanIo>::read(src, &mut *offset)? {
                    $(
                        $disc => Ok(Self::$var(lumine_protocol::CanIo::read(src, &mut *offset)?)),
                    )*
                    _ => Err(lumine_protocol::DecodeError::OutOfRange),
                }
            }
        }
    };
}

can_io! {
    enum Event : i32 {
        Unknown = -1,
        UpdateUser = 0x00,
        ServerSendData = 0x01,
        Heartbeat = 0x02,
        AlertNotification = 0x03,
        RequestPunishment = 0x04,
        CommandRequest = 0x05,
        CommandResponse = 0x06,
        LagCompensation = 0x07,
    }
}

can_io! {
    struct Unknown {}
}

can_io! {
    struct Heartbeat {}
}
