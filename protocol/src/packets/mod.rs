mod login;
pub use login::Login;

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
        impl crate::CanIo for $ident {
            fn write(&self, vec: &mut Vec<u8>) {
                $(
                    crate::CanIo::write(&self.$field, &mut *vec);
                )*
            }

            fn read(src: &[u8], offset: &mut usize) -> Result<Self, crate::DecodeError> {
                Ok(Self {
                    $(
                        $field: crate::CanIo::read(src, &mut *offset)?,
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

        impl crate::CanIo for $ident {
            fn write(&self, vec: &mut Vec<u8>) {
                match self {
                    $(
                        Self::$var(value) => {
                            <$disty as crate::CanIo>::write(&$disc, &mut *vec);
                            crate::CanIo::write(value, &mut *vec);
                        },
                    )*
                }
            }

            fn read(src: &[u8], offset: &mut usize) -> Result<Self, crate::DecodeError> {
                match <$disty as crate::CanIo>::read(src, &mut *offset)? {
                    $(
                        $disc => Ok(Self::$var(crate::CanIo::read(src, &mut *offset)?)),
                    )*
                    _ => Err(crate::DecodeError::OutOfRange),
                }
            }
        }
    };
}

can_io! {
    enum Packets : u8 {
        Login = 0x01,
    }
}